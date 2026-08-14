<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Test\Unit\Model\Token;

use Magento\Framework\Math\Random;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;
use Scr1be\SignedDocumentDelivery\Model\Token\Base64Url;
use Scr1be\SignedDocumentDelivery\Model\Token\SigningKey;
use Scr1be\SignedDocumentDelivery\Model\Token\TokenIssuer;
use Scr1be\SignedDocumentDelivery\Model\Token\TokenPayload;

class TokenIssuerTest extends TestCase
{
    private const KEY = 'a-fixed-32-byte-signing-key-000!';
    private const NOW = 1_775_000_000;
    private const TTL = 300;
    private const NONCE = 'noncenoncenonce1';

    private SigningKey&MockObject $signingKey;
    private Random&MockObject $random;
    private DateTime&MockObject $dateTime;
    private TokenIssuer $issuer;

    protected function setUp(): void
    {
        $this->signingKey = $this->createMock(SigningKey::class);
        $this->signingKey->method('get')->willReturn(self::KEY);

        $this->random = $this->createMock(Random::class);
        $this->random->method('getRandomString')->willReturn(self::NONCE);

        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        $this->issuer = new TokenIssuer($this->signingKey, new Json(), $this->random, $this->dateTime);
    }

    public function testTheTokenIsTwoBase64UrlPartsSeparatedByADot(): void
    {
        $token = $this->issuer->issue(DocumentType::INVOICE, 'NA==', 42, 1, self::TTL)->value;

        $parts = explode('.', $token);

        $this->assertCount(2, $parts);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $parts[0]);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $parts[1]);
    }

    public function testTheMacCoversTheEncodedPayloadExactly(): void
    {
        // This is the property the verifier depends on to check the signature before parsing
        // anything: the signed bytes are the transmitted bytes, with no canonicalisation in between.
        [$encodedPayload, $mac] = explode('.', $this->issuer->issue(DocumentType::ORDER, 'MQ==', 7, 3, self::TTL)->value);

        $this->assertSame(
            Base64Url::encode(hash_hmac('sha256', $encodedPayload, self::KEY, true)),
            $mac
        );
    }

    public function testThePayloadCarriesTheRequestAndAnExpiryDerivedFromTheMagentoClock(): void
    {
        $token = $this->issuer->issue(DocumentType::SHIPMENT, 'MDAwMDAwMDAx', 42, 2, self::TTL);

        $decoded = json_decode((string) Base64Url::decode(explode('.', $token->value)[0]), true);

        $this->assertSame([
            'v' => TokenPayload::VERSION,
            't' => 'SHIPMENT',
            'd' => 'MDAwMDAwMDAx',
            'c' => 42,
            's' => 2,
            'x' => self::NOW + self::TTL,
            'n' => self::NONCE,
        ], $decoded);
    }

    public function testTheReturnedPayloadMatchesTheOneInTheToken(): void
    {
        // The mutation answers expires_at / expires_in from this rather than by re-parsing.
        $token = $this->issuer->issue(DocumentType::CREDITMEMO, 'OQ==', 9, 1, self::TTL);

        $this->assertSame(DocumentType::CREDITMEMO, $token->payload->type);
        $this->assertSame('OQ==', $token->payload->uid);
        $this->assertSame(9, $token->payload->customerId);
        $this->assertSame(1, $token->payload->storeId);
        $this->assertSame(self::NOW + self::TTL, $token->payload->expiresAt);
    }

    public function testTwoIssuesOfTheSameRequestDifferBecauseOfTheNonce(): void
    {
        $random = $this->createMock(Random::class);
        $random->method('getRandomString')->willReturnOnConsecutiveCalls('firstnonce000001', 'secondnonce00002');
        $issuer = new TokenIssuer($this->signingKey, new Json(), $random, $this->dateTime);

        $this->assertNotSame(
            $issuer->issue(DocumentType::INVOICE, 'NA==', 42, 1, self::TTL)->value,
            $issuer->issue(DocumentType::INVOICE, 'NA==', 42, 1, self::TTL)->value
        );
    }
}
