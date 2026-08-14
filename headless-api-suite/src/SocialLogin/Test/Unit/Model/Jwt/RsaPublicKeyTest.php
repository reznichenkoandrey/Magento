<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Test\Unit\Model\Jwt;

use PHPUnit\Framework\TestCase;
use Scr1be\SocialLogin\Model\Jwt\Base64Url;
use Scr1be\SocialLogin\Model\Jwt\RsaPublicKey;

/**
 * The DER conversion is the one piece of this module that cannot be reasoned about by reading it.
 *
 * So it is tested against OpenSSL itself: generate a real key pair, take the modulus and exponent out
 * of it the way a JWK carries them, rebuild a PEM from those two numbers, and check that OpenSSL
 * verifies a signature made with the private half. If a single length byte is wrong, this fails.
 *
 * @requires extension openssl
 */
class RsaPublicKeyTest extends TestCase
{
    /**
     * @var array{n: string, e: string}
     */
    private array $jwk;

    /**
     * @var \OpenSSLAsymmetricKey
     */
    private $privateKey;

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
        $this->jwk = [
            'n' => Base64Url::encode($details['rsa']['n']),
            'e' => Base64Url::encode($details['rsa']['e']),
        ];
    }

    public function testTheRebuiltKeyVerifiesARealSignature(): void
    {
        $pem = RsaPublicKey::toPem($this->jwk['n'], $this->jwk['e']);
        $this->assertIsString($pem);

        $payload = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiIxMjM0NSJ9';
        openssl_sign($payload, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        $this->assertSame(1, openssl_verify($payload, $signature, $pem, OPENSSL_ALGO_SHA256));
    }

    public function testTheRebuiltKeyMatchesWhatOpenSslWouldHaveWritten(): void
    {
        $expected = openssl_pkey_get_details($this->privateKey)['key'];

        $this->assertSame(
            $this->normalise($expected),
            $this->normalise((string)RsaPublicKey::toPem($this->jwk['n'], $this->jwk['e']))
        );
    }

    /**
     * A 2048-bit modulus starts with a high bit set roughly half the time. Forcing the case makes the
     * leading-zero rule a deterministic assertion rather than a coin flip on every CI run.
     */
    public function testAModulusWithTheHighBitSetGetsALeadingZero(): void
    {
        $modulus = "\xff" . str_repeat("\x11", 255);
        $pem = RsaPublicKey::toPem(Base64Url::encode($modulus), Base64Url::encode("\x01\x00\x01"));

        $der = base64_decode(
            str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n"], '', (string)$pem),
            true
        );

        $this->assertIsString($der);
        $this->assertStringContainsString(
            "\x02\x82\x01\x01\x00\xff",
            $der,
            'INTEGER of 257 bytes: a zero pad, then the 0xff first byte of the modulus'
        );
    }

    public function testRejectsUndecodableMembers(): void
    {
        $this->assertNull(RsaPublicKey::toPem('!!!!', 'AQAB'));
        $this->assertNull(RsaPublicKey::toPem('', 'AQAB'));
    }

    private function normalise(string $pem): string
    {
        return preg_replace('/\s+/', '', $pem) ?? '';
    }
}
