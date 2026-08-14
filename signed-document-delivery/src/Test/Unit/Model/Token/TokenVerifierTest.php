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
use Scr1be\SignedDocumentDelivery\Model\Token\InvalidTokenException;
use Scr1be\SignedDocumentDelivery\Model\Token\SigningKey;
use Scr1be\SignedDocumentDelivery\Model\Token\TokenIssuer;
use Scr1be\SignedDocumentDelivery\Model\Token\TokenPayload;
use Scr1be\SignedDocumentDelivery\Model\Token\TokenVerifier;

class TokenVerifierTest extends TestCase
{
    private const KEY = 'a-fixed-32-byte-signing-key-000!';
    private const OTHER_KEY = 'the-key-after-a-rotation-00000!!';
    private const ISSUED_AT = 1_775_000_000;
    private const TTL = 300;

    private DateTime&MockObject $dateTime;
    private TokenVerifier $verifier;

    protected function setUp(): void
    {
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtTimestamp')->willReturn(self::ISSUED_AT + 1);

        $this->verifier = new TokenVerifier($this->signingKey(self::KEY), new Json(), $this->dateTime);
    }

    public function testAFreshTokenRoundTripsBackToItsPayload(): void
    {
        $payload = $this->verifier->verify($this->issue(DocumentType::INVOICE, 'NA==', 42, 1));

        $this->assertSame(DocumentType::INVOICE, $payload->type);
        $this->assertSame('NA==', $payload->uid);
        $this->assertSame(42, $payload->customerId);
        $this->assertSame(1, $payload->storeId);
        $this->assertSame(self::ISSUED_AT + self::TTL, $payload->expiresAt);
    }

    public function testAStoreIdOfZeroSurvives(): void
    {
        // Store 0 is the admin store. It is a legitimate integer, and a payload validator written
        // as "> 0" would silently reject it, so the boundary is pinned here.
        $this->assertSame(0, $this->verifier->verify($this->issue(DocumentType::ORDER, 'MQ==', 42, 0))->storeId);
    }

    public function testATamperedPayloadIsRejected(): void
    {
        // Re-encode the payload with a different customer id, keeping the original MAC — the
        // attack the signature exists for.
        [$encodedPayload, $mac] = explode('.', $this->issue(DocumentType::INVOICE, 'NA==', 42, 1));
        $claims = json_decode((string) Base64Url::decode($encodedPayload), true);
        $claims['c'] = 43;

        $this->assertRefusedBecause(
            'signature does not match',
            Base64Url::encode((string) json_encode($claims)) . '.' . $mac
        );
    }

    public function testATokenSignedWithAnotherKeyIsRejected(): void
    {
        $foreign = new TokenIssuer(
            $this->signingKey(self::OTHER_KEY),
            new Json(),
            $this->randomStub(),
            $this->clockAt(self::ISSUED_AT)
        );

        $this->assertRefusedBecause(
            'signature does not match',
            $foreign->issue(DocumentType::INVOICE, 'NA==', 42, 1, self::TTL)->value
        );
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $verifier = new TokenVerifier(
            $this->signingKey(self::KEY),
            new Json(),
            $this->clockAt(self::ISSUED_AT + self::TTL + 1)
        );

        $this->assertRefusedBecause(
            'link expired at ' . (self::ISSUED_AT + self::TTL),
            $this->issue(DocumentType::INVOICE, 'NA==', 42, 1),
            $verifier
        );
    }

    public function testATokenIsAlreadyDeadOnTheSecondItExpires(): void
    {
        // The comparison is `<=`, so the expiry second itself is gone. One second either way is not
        // worth arguing about; a boundary nobody pinned is.
        $verifier = new TokenVerifier(
            $this->signingKey(self::KEY),
            new Json(),
            $this->clockAt(self::ISSUED_AT + self::TTL)
        );

        $this->assertRefusedBecause(
            'link expired at ' . (self::ISSUED_AT + self::TTL),
            $this->issue(DocumentType::INVOICE, 'NA==', 42, 1),
            $verifier
        );
    }

    /**
     * @dataProvider malformedTokens
     */
    public function testMalformedInputNeverReachesTheParser(string $token, string $expectedReason): void
    {
        $this->assertRefusedBecause($expectedReason, $token);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function malformedTokens(): array
    {
        return [
            'empty' => ['', 'token is not <payload>.<mac>'],
            'no separator' => ['justonepart', 'token is not <payload>.<mac>'],
            'three parts' => ['a.b.c', 'token is not <payload>.<mac>'],
            'empty payload' => ['.abc', 'token is not <payload>.<mac>'],
            'empty mac' => ['abc.', 'token is not <payload>.<mac>'],
            // Well-formed shape, wrong signature. This is as far as unsigned input ever gets: no
            // base64 decode, no JSON parse, no enum lookup.
            'random garbage' => ['aGVsbG8.d29ybGQ', 'signature does not match'],
        ];
    }

    public function testAPayloadFromAFutureFormatIsRejectedEvenThoughItIsCorrectlySigned(): void
    {
        $this->assertRefusedBecause(
            'payload is not a version ' . TokenPayload::VERSION . ' payload',
            $this->sign(['v' => TokenPayload::VERSION + 1, 't' => 'INVOICE'])
        );
    }

    /**
     * @param array<string, mixed> $claims
     * @dataProvider unusablePayloads
     */
    public function testASignedButUnusablePayloadIsRejected(array $claims): void
    {
        $this->assertRefusedBecause(
            'payload is not a version ' . TokenPayload::VERSION . ' payload',
            $this->sign($claims)
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unusablePayloads(): array
    {
        $valid = [
            'v' => TokenPayload::VERSION,
            't' => 'INVOICE',
            'd' => 'NA==',
            'c' => 42,
            's' => 1,
            'x' => self::ISSUED_AT + self::TTL,
            'n' => 'nonce',
        ];

        return [
            'unknown document type' => [['t' => 'PACKING_LIST'] + $valid],
            'customer id as a string' => [['c' => '42'] + $valid],
            'customer id of zero' => [['c' => 0] + $valid],
            'negative store id' => [['s' => -1] + $valid],
            'expiry as a string' => [['x' => (string) (self::ISSUED_AT + self::TTL)] + $valid],
            'empty uid' => [['d' => ''] + $valid],
            'missing nonce' => [array_diff_key($valid, ['n' => null])],
        ];
    }

    public function testSignedNonJsonIsRejectedWithoutAFatal(): void
    {
        $encoded = Base64Url::encode('this is not json');

        $this->assertRefusedBecause(
            'payload is not JSON',
            $encoded . '.' . Base64Url::encode(hash_hmac('sha256', $encoded, self::KEY, true))
        );
    }

    public function testSignedJsonThatIsNotAnObjectIsRejected(): void
    {
        $encoded = Base64Url::encode('"a bare string"');

        $this->assertRefusedBecause(
            'payload is not an object',
            $encoded . '.' . Base64Url::encode(hash_hmac('sha256', $encoded, self::KEY, true))
        );
    }

    public function testTheClientFacingMessageNeverCarriesTheReason(): void
    {
        try {
            $this->verifier->verify('nonsense');
            $this->fail('expected the verifier to refuse');
        } catch (InvalidTokenException $e) {
            $this->assertSame('The download link is not valid.', $e->getMessage());
            $this->assertStringNotContainsString($e->reason, $e->getMessage());
        }
    }

    /**
     * The reason is a server-side field, not the message, so it cannot be asserted with
     * expectException* — those look at the message, which is identical for every failure.
     */
    private function assertRefusedBecause(string $reason, string $token, ?TokenVerifier $verifier = null): void
    {
        try {
            ($verifier ?? $this->verifier)->verify($token);
            $this->fail('expected the verifier to refuse: ' . $reason);
        } catch (InvalidTokenException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function sign(array $claims): string
    {
        $encoded = Base64Url::encode((string) json_encode($claims));

        return $encoded . '.' . Base64Url::encode(hash_hmac('sha256', $encoded, self::KEY, true));
    }

    private function issue(DocumentType $type, string $uid, int $customerId, int $storeId): string
    {
        $issuer = new TokenIssuer(
            $this->signingKey(self::KEY),
            new Json(),
            $this->randomStub(),
            $this->clockAt(self::ISSUED_AT)
        );

        return $issuer->issue($type, $uid, $customerId, $storeId, self::TTL)->value;
    }

    private function signingKey(string $key): SigningKey
    {
        $mock = $this->createMock(SigningKey::class);
        $mock->method('get')->willReturn($key);

        return $mock;
    }

    private function randomStub(): Random
    {
        $mock = $this->createMock(Random::class);
        $mock->method('getRandomString')->willReturn('noncenoncenonce1');

        return $mock;
    }

    private function clockAt(int $timestamp): DateTime
    {
        $mock = $this->createMock(DateTime::class);
        $mock->method('gmtTimestamp')->willReturn($timestamp);

        return $mock;
    }
}
