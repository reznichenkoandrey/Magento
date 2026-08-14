<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Token;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Checks a token, in an order that matters.
 *
 * 1. Split. Exactly two non-empty parts, or nothing else happens.
 * 2. Recompute the MAC over the *encoded* payload and compare it with `hash_equals()`.
 * 3. Only then decode, parse and check the expiry.
 *
 * Step 2 before step 3 is the whole point. A verifier that decodes first — reads the JSON, looks at
 * the expiry, then gets around to the signature — has already run a JSON parser, an enum lookup and
 * a handful of type checks over bytes an attacker chose. That is attack surface reached before
 * authentication, and it is where deserialisation bugs live. Here, unsigned input never reaches a
 * parser at all.
 *
 * `hash_equals()` rather than `===` because the comparison is against a value the attacker supplies
 * and can vary one byte at a time. PHP's string comparison returns on the first differing byte;
 * over enough samples that timing difference is a signature oracle. `hash_equals()` compares in
 * time proportional to the length and not to the content.
 *
 * The MACs are compared in their base64url form. They are fixed-length (43 characters for 32 raw
 * bytes) and character-for-character equal exactly when the raw bytes are, so nothing is lost — and
 * a malformed base64url MAC never has to be decoded to be rejected.
 */
class TokenVerifier
{
    private const HASH_ALGORITHM = 'sha256';

    private const PART_SEPARATOR = '.';

    private const EXPECTED_PARTS = 2;

    public function __construct(
        private readonly SigningKey $signingKey,
        private readonly Json $json,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @throws InvalidTokenException
     */
    public function verify(string $token): TokenPayload
    {
        $parts = explode(self::PART_SEPARATOR, $token);
        if (count($parts) !== self::EXPECTED_PARTS || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidTokenException('token is not <payload>.<mac>');
        }

        [$encodedPayload, $providedMac] = $parts;

        $expectedMac = Base64Url::encode(
            hash_hmac(self::HASH_ALGORITHM, $encodedPayload, $this->signingKey->get(), true)
        );

        if (!hash_equals($expectedMac, $providedMac)) {
            throw new InvalidTokenException('signature does not match');
        }

        // Everything below here is running on bytes this installation signed itself.
        $decoded = Base64Url::decode($encodedPayload);
        if ($decoded === null) {
            throw new InvalidTokenException('payload is not base64url');
        }

        try {
            $data = $this->json->unserialize($decoded);
        } catch (\InvalidArgumentException) {
            throw new InvalidTokenException('payload is not JSON');
        }

        if (!is_array($data)) {
            throw new InvalidTokenException('payload is not an object');
        }

        $payload = TokenPayload::fromArray($data);
        if ($payload === null) {
            throw new InvalidTokenException('payload is not a version ' . TokenPayload::VERSION . ' payload');
        }

        if ($payload->expiresAt <= $this->dateTime->gmtTimestamp()) {
            throw new InvalidTokenException('link expired at ' . $payload->expiresAt);
        }

        return $payload;
    }
}
