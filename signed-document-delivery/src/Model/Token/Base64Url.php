<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Token;

/**
 * RFC 4648 §5 base64url, because the token travels in a query string.
 *
 * Plain base64 produces `+`, `/` and `=`, all of which have to be percent-encoded in a URL and all
 * of which come back mangled from at least one popular HTTP client, one email client and one
 * analytics rewriter. Encoding them away means the token survives being copied by hand.
 */
final class Base64Url
{
    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return string|null Null when the input is not valid base64url
     */
    public static function decode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9\-_]+$/', $encoded) !== 1) {
            return null;
        }

        // Padding is stripped on encode; base64_decode() in strict mode wants it back.
        $padded = str_pad($encoded, (int) (ceil(strlen($encoded) / 4) * 4), '=');
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
