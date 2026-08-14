<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model\Jwt;

/**
 * base64url (RFC 7515 §2) — base64 with `+/` swapped for `-_` and padding stripped.
 *
 * Its own class because every one of the three places that needs it needs the *strict* decode: a
 * segment that is not valid base64 must come back as false rather than as silently-dropped bytes,
 * because the bytes in question are a signature.
 */
final class Base64Url
{
    /**
     * @param string $data
     * @return string
     */
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decode, or null if the input is not well-formed base64url.
     *
     * @param string $data
     * @return string|null
     */
    public static function decode(string $data): ?string
    {
        $padding = strlen($data) % 4;
        if ($padding === 1) {
            // A base64 string can never be 4n+1 characters long.
            return null;
        }
        if ($padding !== 0) {
            $data .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
