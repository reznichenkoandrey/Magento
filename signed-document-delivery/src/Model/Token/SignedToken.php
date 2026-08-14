<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Token;

/**
 * A freshly issued token together with the payload that went into it.
 *
 * The mutation needs both: the string for the URL, and the expiry for `expires_at` / `expires_in`.
 * Handing back only the string would mean parsing it again to answer a question the issuer already
 * knew the answer to.
 */
final class SignedToken
{
    public function __construct(
        public readonly string $value,
        public readonly TokenPayload $payload
    ) {
    }
}
