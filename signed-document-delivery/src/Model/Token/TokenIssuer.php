<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Token;

use Magento\Framework\Math\Random;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;

/**
 * Mints `<payload>.<mac>`, both base64url.
 *
 * The MAC covers the *encoded* payload, not the decoded one. That is what lets the verifier check
 * the signature before parsing anything — see TokenVerifier. It also removes a whole class of
 * canonicalisation bug: there is exactly one byte string being signed, and it is the one that
 * travels.
 */
class TokenIssuer
{
    private const HASH_ALGORITHM = 'sha256';

    /**
     * 16 characters out of Random's default alphabet. The nonce is not a secret and is not checked
     * on the way back; it exists so two requests for the same document in the same second produce
     * different URLs, which keeps one leaked URL from being interchangeable with another and stops
     * intermediaries treating the two as the same cacheable resource.
     */
    private const NONCE_LENGTH = 16;

    public function __construct(
        private readonly SigningKey $signingKey,
        private readonly Json $json,
        private readonly Random $random,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @param int $ttl Seconds the URL stays valid, already clamped by Config
     * @throws \Magento\Framework\Exception\LocalizedException When there is no crypt key to derive from
     */
    public function issue(DocumentType $type, string $uid, int $customerId, int $storeId, int $ttl): SignedToken
    {
        // gmtTimestamp() rather than time(): the same clock the verifier reads, and the one Magento
        // uses everywhere else, so a host with a skewed process timezone cannot make tokens that
        // are born expired.
        $expiresAt = $this->dateTime->gmtTimestamp() + $ttl;

        $payload = new TokenPayload(
            $type,
            $uid,
            $customerId,
            $storeId,
            $expiresAt,
            $this->random->getRandomString(self::NONCE_LENGTH)
        );

        $encodedPayload = Base64Url::encode($this->json->serialize($payload->toArray()));
        $mac = hash_hmac(self::HASH_ALGORITHM, $encodedPayload, $this->signingKey->get(), true);

        return new SignedToken($encodedPayload . '.' . Base64Url::encode($mac), $payload);
    }
}
