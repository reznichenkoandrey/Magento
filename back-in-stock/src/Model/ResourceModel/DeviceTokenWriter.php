<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * `scr1be_push_device_token`, in three statements.
 *
 * There is no `AbstractDb` resource model and no model class, because there is no entity here that
 * anyone loads, edits and saves. A device token is written by an upsert, read as a flat list of
 * strings, and retired by a bulk update — three shapes that a model would only get in the way of.
 */
class DeviceTokenWriter
{
    public const TABLE = 'scr1be_push_device_token';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Record a device, or bring an existing record up to date.
     *
     * The unique key is the hash, so this is one statement whether the token is new or has been
     * registered on every page load for a year.
     *
     * Two of the updated columns are worth naming:
     *
     *  - `customer_id` is overwritten unconditionally, *including with null*. A browser that
     *    registers as a guest after having registered as a customer has been logged out of, and a
     *    row that kept the old customer id would push one person's back-in-stock alerts to whoever
     *    is using the machine now.
     *  - `is_active` goes back to 1. A token the transport retired and the browser has since
     *    presented again is a token the browser believes in; the transport's opinion was a snapshot,
     *    the registration is current.
     */
    public function upsert(
        string $tokenHash,
        string $token,
        ?int $customerId,
        int $websiteId,
        string $platform
    ): void {
        $connection = $this->resource->getConnection();

        $connection->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'token_hash' => $tokenHash,
                'token' => $token,
                'customer_id' => $customerId,
                'website_id' => $websiteId,
                'platform' => $platform,
                'is_active' => 1,
                'deactivated_reason' => null,
            ],
            ['token', 'customer_id', 'website_id', 'platform', 'is_active', 'deactivated_reason']
        );
    }

    /**
     * The tokens a customer can be reached on.
     *
     * @return string[]
     */
    public function readActiveTokens(int $customerId, int $websiteId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['token'])
            ->where('customer_id = ?', $customerId)
            ->where('website_id = ?', $websiteId)
            ->where('is_active = ?', 1);

        return array_map('strval', $connection->fetchCol($select));
    }

    /**
     * Retire tokens the transport reported as permanently refused.
     *
     * A soft deactivation rather than a delete, and not for sentimental reasons: the same browser
     * frequently comes back with the same token after a service worker reinstall, and the row
     * carries the customer association and the reason it was retired. `upsert()` revives it in one
     * statement; a deleted row would come back as a new registration with no history and no
     * explanation of the gap.
     *
     * @param string[] $tokens Raw tokens, as the transport had them.
     * @return int Rows retired.
     */
    public function deactivate(array $tokens, string $reason): int
    {
        $hashes = array_values(array_unique(array_map(
            static fn (string $token): string => hash('sha256', $token),
            array_filter($tokens, static fn ($token): bool => is_string($token) && $token !== '')
        )));

        if ($hashes === []) {
            return 0;
        }

        $connection = $this->resource->getConnection();

        return (int)$connection->update(
            $this->resource->getTableName(self::TABLE),
            ['is_active' => 0, 'deactivated_reason' => substr($reason, 0, 64)],
            [
                'token_hash IN (?)' => $hashes,
                'is_active = ?' => 1,
            ]
        );
    }
}
