<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * The device table, as a gateway.
 *
 * Not an entity with a repository, for the same reason the social-link table is not: there is no
 * lifecycle, no admin screen and no service contract worth exposing. Four operations, all of them
 * one statement.
 */
class DeviceRegistry
{
    public const TABLE = 'scr1be_headless_push_device';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * The identity of a token, everywhere it is stored as a reference.
     *
     * @param string $token
     * @return string
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Register a device, or refresh what is known about one already registered.
     *
     * `insertOnDuplicate` because a re-registration is the normal case: apps re-send their token on
     * every launch, and a select-then-insert races against itself when two launches overlap.
     *
     * Re-registering also reactivates. A token that FCM once reported as UNREGISTERED can become
     * live again — the app was reinstalled and the platform reissued the same string — and the fact
     * that a client is presenting it right now is better evidence than a stale rejection.
     *
     * @param string $token
     * @param int|null $customerId
     * @param int $storeId
     * @param string $platform
     * @return string The token hash.
     */
    public function register(string $token, ?int $customerId, int $storeId, string $platform): string
    {
        $hash = self::hash($token);

        $this->resourceConnection->getConnection()->insertOnDuplicate(
            $this->tableName(),
            [
                'token_hash' => $hash,
                'token' => $token,
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'platform' => $platform,
                'is_active' => 1,
                'deactivated_reason' => null,
            ],
            ['customer_id', 'store_id', 'platform', 'is_active', 'deactivated_reason']
        );

        return $hash;
    }

    /**
     * The live token for one hash, or null.
     *
     * @param string $tokenHash
     * @return string|null
     */
    public function findActiveToken(string $tokenHash): ?string
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->tableName(), ['token'])
            ->where('token_hash = ?', $tokenHash)
            ->where('is_active = ?', 1)
            ->limit(1);

        $token = $connection->fetchOne($select);

        return $token === false || $token === null || $token === '' ? null : (string)$token;
    }

    /**
     * Every live token belonging to one customer.
     *
     * Used when an order has no device of its own — a customer who ordered on the web and has the app
     * installed still wants to know their parcel shipped.
     *
     * @param int $customerId
     * @return string[]
     */
    public function findActiveTokensForCustomer(int $customerId): array
    {
        if ($customerId === 0) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->tableName(), ['token'])
            ->where('customer_id = ?', $customerId)
            ->where('is_active = ?', 1);

        return array_values(array_map('strval', $connection->fetchCol($select)));
    }

    /**
     * Stop trying a token the transport has rejected permanently.
     *
     * Deactivated rather than deleted: the row records that this device existed and why it stopped
     * working, which is the difference between diagnosing "the app never registered" and "the app
     * registered and was uninstalled".
     *
     * @param string $token
     * @param string $reason
     * @return void
     */
    public function deactivate(string $token, string $reason): void
    {
        $this->resourceConnection->getConnection()->update(
            $this->tableName(),
            ['is_active' => 0, 'deactivated_reason' => substr($reason, 0, 64)],
            ['token_hash = ?' => self::hash($token)]
        );
    }

    /**
     * Attach every unclaimed registration of these tokens to a customer.
     *
     * @param string $tokenHash
     * @param int $customerId
     * @return void
     */
    public function claim(string $tokenHash, int $customerId): void
    {
        if ($customerId === 0) {
            return;
        }

        $this->resourceConnection->getConnection()->update(
            $this->tableName(),
            ['customer_id' => $customerId],
            ['token_hash = ?' => $tokenHash, 'customer_id IS NULL']
        );
    }

    /**
     * @return string
     */
    private function tableName(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
