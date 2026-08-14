<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Token;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Exception\LocalizedException;

/**
 * The HMAC key, derived one-way from the installation's crypt key.
 *
 * The crypt key is not used directly. It is the key behind `Magento\Framework\Encryption\Encryptor`
 * — customer password hashes, encrypted configuration, OAuth secrets — and a signing key that is
 * *equal* to it means any future weakness in one construction is a weakness in all of them. HKDF
 * with a fixed info string gives a key that is unique to this purpose and from which the crypt key
 * cannot be recovered, at the cost of one hash.
 *
 * Key selection mirrors the Encryptor exactly, and deliberately: `app/etc/env.php` may hold several
 * keys separated by whitespace after a `setup:config:set --key` rotation, and
 * `Encryptor::__construct()` does `preg_split('/\s+/s', trim($deploymentConfig->get('crypt/key')))`
 * and treats the *last* one as current. Using anything else here would sign with a key the
 * installation considers retired.
 *
 * Rotating the crypt key therefore invalidates every URL already handed out. With a default TTL of
 * five minutes that is a five-minute window of 404s during a rotation, which is the right trade
 * against carrying a key list and verifying against all of it.
 *
 * The derived key is memoised for the life of the request. It is not cached anywhere durable: a
 * signing key on disk or in Redis is a signing key with a second place to leak from.
 */
class SigningKey
{
    /**
     * Domain separation for HKDF. Changing this string invalidates every outstanding token, which
     * is exactly what you want if the token format ever changes incompatibly.
     */
    private const KEY_INFO = 'scr1be/signed-document-delivery:url-signing:v1';

    private const KEY_LENGTH_BYTES = 32;

    private const HASH_ALGORITHM = 'sha256';

    private ?string $derived = null;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * @return string 32 raw bytes
     * @throws LocalizedException When the installation has no crypt key at all
     */
    public function get(): string
    {
        if ($this->derived !== null) {
            return $this->derived;
        }

        $configured = trim((string) $this->deploymentConfig->get(Encryptor::PARAM_CRYPT_KEY));
        if ($configured === '') {
            throw new LocalizedException(
                __('Signed document delivery needs an encryption key in app/etc/env.php.')
            );
        }

        $keys = preg_split('/\s+/s', $configured) ?: [];
        $current = (string) end($keys);

        $this->derived = hash_hkdf(self::HASH_ALGORITHM, $current, self::KEY_LENGTH_BYTES, self::KEY_INFO);

        return $this->derived;
    }
}
