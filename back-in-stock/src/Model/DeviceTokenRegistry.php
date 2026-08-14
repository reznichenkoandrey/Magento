<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model;

use Magento\Framework\Exception\LocalizedException;
use Scr1be\BackInStock\Model\ResourceModel\DeviceTokenWriter;

/**
 * The rules a device token has to pass before it is allowed into the table.
 *
 * All of them exist because the value arrives from a browser, over a public endpoint, and is stored
 * verbatim. The endpoint validates nothing itself: everything an attacker could try — an
 * unbounded string, a token for someone else's platform, an empty registration to churn rows — is
 * refused here, once, and the same rules apply to the storefront endpoint and to any future job that
 * imports tokens.
 */
class DeviceTokenRegistry
{
    /**
     * Long enough for an FCM registration token with room to spare, short enough that the endpoint
     * cannot be used to write kilobytes per request.
     */
    private const MAX_TOKEN_LENGTH = 512;

    /** Short enough that a token cannot be a stray whitespace string. */
    private const MIN_TOKEN_LENGTH = 32;

    private const PLATFORMS = ['web', 'ios', 'android'];
    private const DEFAULT_PLATFORM = 'web';

    public function __construct(
        private readonly DeviceTokenWriter $writer
    ) {
    }

    /**
     * @throws LocalizedException When the token is not something worth storing.
     */
    public function register(string $token, ?int $customerId, int $websiteId, string $platform): void
    {
        $token = trim($token);
        $length = strlen($token);

        if ($length < self::MIN_TOKEN_LENGTH || $length > self::MAX_TOKEN_LENGTH) {
            throw new LocalizedException(__('That does not look like a device token.'));
        }

        // A registration token is URL-safe base64 with the odd colon in it. Anything else is either
        // an encoding accident or somebody probing what the column will accept.
        if (preg_match('/^[A-Za-z0-9_:.\-]+$/', $token) !== 1) {
            throw new LocalizedException(__('That does not look like a device token.'));
        }

        $this->writer->upsert(
            hash('sha256', $token),
            $token,
            $customerId !== null && $customerId > 0 ? $customerId : null,
            $websiteId,
            in_array($platform, self::PLATFORMS, true) ? $platform : self::DEFAULT_PLATFORM
        );
    }

    /**
     * @return string[]
     */
    public function getActiveTokens(int $customerId, int $websiteId): array
    {
        return $this->writer->readActiveTokens($customerId, $websiteId);
    }

    /**
     * @param string[] $tokens
     */
    public function retire(array $tokens, string $reason): int
    {
        return $this->writer->deactivate($tokens, $reason);
    }
}
