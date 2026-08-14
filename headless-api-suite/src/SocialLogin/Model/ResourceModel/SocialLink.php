<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The social-link table, as a small purpose-built gateway rather than a model plus a repository.
 *
 * There is no entity here in any useful sense: the row has no lifecycle, no admin screen and no
 * service contract worth exposing. It is a unique index with three columns hanging off it, and the
 * only three operations anyone performs on it are "find the customer", "record the link" and "note
 * that they signed in". A model, a resource model, a collection, a repository and an interface would
 * be five files restating a unique key.
 */
class SocialLink
{
    public const TABLE = 'scr1be_social_login_link';

    /**
     * @param ResourceConnection $resourceConnection
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * The customer linked to this identity on this website, or null.
     *
     * @param string $provider
     * @param string $subject
     * @param int $websiteId
     * @return int|null
     */
    public function findCustomerId(string $provider, string $subject, int $websiteId): ?int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->tableName(), ['customer_id'])
            ->where('provider = ?', $provider)
            ->where('subject = ?', $subject)
            ->where('website_id = ?', $websiteId)
            ->limit(1);

        $customerId = $connection->fetchOne($select);

        return $customerId === false || $customerId === null ? null : (int)$customerId;
    }

    /**
     * Record the link, or leave the existing one alone.
     *
     * `INSERT ... ON DUPLICATE KEY UPDATE` rather than select-then-insert, because two sign-ins from
     * the same person a millisecond apart both find nothing and both insert. The unique index makes
     * the second one a no-op instead of an exception; doing the check in PHP makes it a 500.
     *
     * @param string $provider
     * @param string $subject
     * @param int $customerId
     * @param int $websiteId
     * @return void
     */
    public function link(string $provider, string $subject, int $customerId, int $websiteId): void
    {
        $connection = $this->resourceConnection->getConnection();

        $connection->insertOnDuplicate(
            $this->tableName(),
            [
                'provider' => $provider,
                'subject' => $subject,
                'customer_id' => $customerId,
                'website_id' => $websiteId,
                'last_login_at' => $this->dateTime->gmtDate(),
            ],
            // `customer_id` is deliberately absent from the update list. Re-pointing an existing
            // (provider, subject, website) link at a different account is an account takeover
            // primitive, not an upsert; if the identity is already linked, the link stands and only
            // the timestamp moves.
            ['last_login_at']
        );
    }

    /**
     * Note a successful sign-in through an existing link.
     *
     * @param string $provider
     * @param string $subject
     * @param int $websiteId
     * @return void
     */
    public function touch(string $provider, string $subject, int $websiteId): void
    {
        $connection = $this->resourceConnection->getConnection();

        $connection->update(
            $this->tableName(),
            ['last_login_at' => $this->dateTime->gmtDate()],
            [
                'provider = ?' => $provider,
                'subject = ?' => $subject,
                'website_id = ?' => $websiteId,
            ]
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
