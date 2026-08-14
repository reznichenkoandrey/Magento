<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Test\Unit\Model\Verifier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\SocialLogin\Model\Jwt\Base64Url;
use Scr1be\SocialLogin\Model\Jwt\JwksProvider;
use Scr1be\SocialLogin\Model\Jwt\RsaPublicKey;
use Scr1be\SocialLogin\Model\SocialLoginException;

/**
 * Signature and claim validation, exercised with real RS256 signatures rather than with a stubbed
 * "is the signature valid" boolean — the interesting failures in a JWT verifier are all in the parts
 * a stub would paper over.
 *
 * @requires extension openssl
 */
class AbstractVerifierTest extends TestCase
{
    private const CLIENT_ID = 'client-123.apps.example.com';
    private const KEY_ID = 'kid-1';
    private const NOW = 1_700_000_000;

    /**
     * @var \OpenSSLAsymmetricKey
     */
    private $privateKey;

    private string $pem;
    private JwksProvider&MockObject $jwksProvider;
    private LoggerInterface&MockObject $logger;
    private TestableVerifier $verifier;

    protected function setUp(): void
    {
        $this->privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($this->privateKey === false) {
            $this->markTestSkipped('OpenSSL could not generate a key pair here.');
        }

        $details = openssl_pkey_get_details($this->privateKey);
        $this->pem = (string)RsaPublicKey::toPem(
            Base64Url::encode($details['rsa']['n']),
            Base64Url::encode($details['rsa']['e'])
        );

        $this->jwksProvider = $this->createMock(JwksProvider::class);
        $this->jwksProvider->method('getPem')->willReturn($this->pem);
        $this->logger = $this->createMock(LoggerInterface::class);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(self::CLIENT_ID);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        $this->verifier = new TestableVerifier(
            $this->jwksProvider,
            $scopeConfig,
            new Json(),
            $dateTime,
            $this->logger
        );
    }

    public function testAcceptsAWellFormedToken(): void
    {
        $claims = $this->verifier->verify($this->token(), 1);

        $this->assertSame('subject-9', $claims->subject);
        $this->assertSame('ada@example.com', $claims->email);
        $this->assertTrue($claims->emailVerified);
    }

    /**
     * The `alg: none` family. The header must be checked before anything trusts the token, and RS256
     * must be the only accepted value.
     */
    public function testRejectsATokenClaimingAnotherAlgorithm(): void
    {
        $this->expectException(SocialLoginException::class);
        $this->verifier->verify($this->token(header: ['alg' => 'none', 'kid' => self::KEY_ID]), 1);
    }

    public function testRejectsATokenWithNoKid(): void
    {
        $this->expectException(SocialLoginException::class);
        $this->verifier->verify($this->token(header: ['alg' => 'RS256']), 1);
    }

    /**
     * A payload edited after signing.
     */
    public function testRejectsATamperedPayload(): void
    {
        $parts = explode('.', $this->token());
        $parts[1] = Base64Url::encode('{"sub":"someone-else","iss":"https://issuer.example.com"}');

        $this->expectException(SocialLoginException::class);
        $this->verifier->verify(implode('.', $parts), 1);
    }

    public function testRejectsATokenSignedByAnotherKey(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $token = $this->token(signWith: $other);

        $this->expectException(SocialLoginException::class);
        $this->verifier->verify($token, 1);
    }

    public function testRejectsAnUnexpectedIssuer(): void
    {
        $this->expectException(SocialLoginException::class);
        $this->verifier->verify($this->token(payload: ['iss' => 'https://evil.example.com']), 1);
    }

    /**
     * Somebody else's Google token is a perfectly valid, correctly signed, unexpired JWT. The
     * audience check is the only thing standing between it and an account here.
     */
    public function testRejectsATokenIssuedForAnotherAudience(): void
    {
        $this->expectException(SocialLoginException::class);
        $this->verifier->verify($this->token(payload: ['aud' => 'someone-else.apps.example.com']), 1);
    }

    /**
     * `aud` may be an array (RFC 7519 §4.1.3), and a token that lists us among several audiences is
     * valid.
     */
    public function testAcceptsAnAudienceArrayContainingTheClientId(): void
    {
        $claims = $this->verifier->verify(
            $this->token(payload: ['aud' => ['other.example.com', self::CLIENT_ID]]),
            1
        );

        $this->assertSame('subject-9', $claims->subject);
    }

    public function testRejectsAnExpiredToken(): void
    {
        $this->expectException(SocialLoginException::class);
        $this->verifier->verify($this->token(payload: ['exp' => self::NOW - 3600]), 1);
    }

    /**
     * A minute of skew is allowed in both directions so that a correct token does not fail because
     * two servers disagree about the second.
     */
    public function testToleratesASmallAmountOfClockSkew(): void
    {
        $claims = $this->verifier->verify(
            $this->token(payload: ['exp' => self::NOW - 30, 'iat' => self::NOW + 30]),
            1
        );

        $this->assertSame('subject-9', $claims->subject);
    }

    public function testRejectsATokenIssuedFarInTheFuture(): void
    {
        $this->expectException(SocialLoginException::class);
        $this->verifier->verify($this->token(payload: ['iat' => self::NOW + 3600]), 1);
    }

    public function testRejectsSomethingThatIsNotAJws(): void
    {
        $this->expectException(SocialLoginException::class);
        $this->verifier->verify('not.a.jwt.at.all', 1);
    }

    public function testRejectsAnUndecodableHeader(): void
    {
        $this->expectException(SocialLoginException::class);
        $this->verifier->verify('!!!.' . Base64Url::encode('{}') . '.' . Base64Url::encode('sig'), 1);
    }

    /**
     * The client is told nothing about which check failed; the operator is told everything.
     */
    public function testTheRejectionIsGenericToTheClientAndSpecificInTheLog(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('unexpected iss'));

        try {
            $this->verifier->verify($this->token(payload: ['iss' => 'https://evil.example.com']), 1);
            $this->fail('Expected rejection');
        } catch (SocialLoginException $e) {
            $this->assertSame(SocialLoginException::INVALID_TOKEN, $e->getErrorCode());
            $this->assertStringNotContainsString('iss', $e->getMessage());
        }
    }

    public function testAnUnconfiguredProviderIsUnavailable(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('   ');
        $verifier = new TestableVerifier(
            $this->jwksProvider,
            $scopeConfig,
            new Json(),
            $this->createMock(DateTime::class),
            $this->logger
        );

        $this->assertFalse($verifier->isAvailable(1));

        try {
            $verifier->verify($this->token(), 1);
            $this->fail('Expected rejection');
        } catch (SocialLoginException $e) {
            $this->assertSame(SocialLoginException::PROVIDER_UNAVAILABLE, $e->getErrorCode());
        }
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload Merged over the defaults.
     * @param \OpenSSLAsymmetricKey|null $signWith
     * @return string
     */
    private function token(array $header = [], array $payload = [], $signWith = null): string
    {
        $header = $header ?: ['alg' => 'RS256', 'kid' => self::KEY_ID];
        $payload = $payload + [
            'iss' => 'https://issuer.example.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'subject-9',
            'email' => 'ada@example.com',
            'email_verified' => true,
            'iat' => self::NOW - 10,
            'exp' => self::NOW + 3600,
        ];

        $signingInput = Base64Url::encode((string)json_encode($header))
            . '.' . Base64Url::encode((string)json_encode($payload));

        openssl_sign($signingInput, $signature, $signWith ?? $this->privateKey, OPENSSL_ALGO_SHA256);

        return $signingInput . '.' . Base64Url::encode($signature);
    }
}
