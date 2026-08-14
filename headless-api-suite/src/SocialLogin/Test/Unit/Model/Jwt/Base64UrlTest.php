<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Test\Unit\Model\Jwt;

use PHPUnit\Framework\TestCase;
use Scr1be\SocialLogin\Model\Jwt\Base64Url;

class Base64UrlTest extends TestCase
{
    /**
     * Round-tripping binary is the point: JWT segments are not text.
     *
     * From 1, not 0 — `random_bytes(0)` is a ValueError on PHP 8. The empty case is its own test
     * below, because it is a contract rather than a length: an empty segment decodes to an empty
     * string rather than to null, and every caller guards on that separately.
     */
    public function testRoundTripsArbitraryBytes(): void
    {
        for ($length = 1; $length < 32; $length++) {
            $bytes = random_bytes($length);
            $this->assertSame($bytes, Base64Url::decode(Base64Url::encode($bytes)), "length {$length}");
        }
    }

    /**
     * An empty segment is well-formed base64url, so it decodes rather than failing.
     *
     * This is deliberate and is why `AbstractVerifier` tests the signature segment for `''` as well
     * as for null: a JWS with an empty signature segment must be rejected as unverifiable, not
     * treated as undecodable, and the distinction lives in the caller rather than here.
     */
    public function testAnEmptyStringDecodesToAnEmptyString(): void
    {
        $this->assertSame('', Base64Url::decode(''));
    }

    public function testUsesTheUrlSafeAlphabetAndNoPadding(): void
    {
        // 0xFB 0xFF encodes to "+/" in standard base64.
        $encoded = Base64Url::encode("\xfb\xff");

        $this->assertSame('-_8', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
    }

    public function testDecodesInputThatStillCarriesPadding(): void
    {
        $this->assertSame("\xfb\xff", Base64Url::decode('-_8='));
    }

    /**
     * Strict decoding matters because the bytes being decoded are a signature: silently dropping the
     * characters OpenSSL would have choked on turns a malformed token into a verification attempt
     * against a truncated signature.
     */
    public function testRejectsCharactersOutsideTheAlphabet(): void
    {
        $this->assertNull(Base64Url::decode('ab*d'));
    }

    /**
     * 4n+1 is not a length any base64 string can have.
     */
    public function testRejectsAnImpossibleLength(): void
    {
        $this->assertNull(Base64Url::decode('abcde'));
    }
}
