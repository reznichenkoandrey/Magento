<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * The arrival log: one row per product, holding the first moment it was ever seen in stock.
 *
 * The module needs this table because the question "is this product a new arrival" has no answer in
 * the core schema. `catalog_product_entity.created_at` is when the row was written, which on any
 * catalogue fed by an ERP is months before the product goes on sale and identical for the whole
 * import batch. `news_from_date` is a merchandising flag someone has to remember to set. What
 * actually marks an arrival is the first time the product could be bought, and nothing records that.
 *
 * "First" is enforced by the storage rather than by a read-then-write, so a restock six months later
 * cannot move the date and two concurrent stock saves cannot race each other into two rows. A
 * product coming back into stock is a different feature with a different audience, and treating it
 * as a new arrival would put last season's stock on the New page every time the warehouse topped it
 * up.
 */
class ArrivalIndex
{
    public const TABLE = 'scr1be_curated_arrival';

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * Record a first arrival, leaving an existing one exactly as it is.
     *
     * The conflict clause writes the primary key back to itself. That looks odd and is the point:
     * `insertOnDuplicate()` treats an empty field list as "update every column"
     * (`Magento\Framework\DB\Adapter\Pdo\Mysql::insertOnDuplicate()` replaces an empty `$fields` with
     * all of them), so the way to express "insert or do nothing" through that API is to nominate a
     * column whose new value equals its old one.
     */
    public function recordArrival(int $productId, string $arrivedAt): void
    {
        if ($productId <= 0) {
            return;
        }

        $this->resourceConnection->getConnection()->insertOnDuplicate(
            $this->resourceConnection->getTableName(self::TABLE),
            ['product_id' => $productId, 'arrived_at' => $arrivedAt],
            ['product_id']
        );
    }

    /**
     * @return string|null UTC `Y-m-d H:i:s`, or null when the product has never been in stock while
     *                     this module was installed.
     */
    public function getArrivalDate(int $productId): ?string
    {
        if ($productId <= 0) {
            return null;
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['arrived_at'])
            ->where('product_id = ?', $productId)
            ->limit(1);

        $arrivedAt = $connection->fetchOne($select);

        return $arrivedAt === false || $arrivedAt === null ? null : (string) $arrivedAt;
    }

    /**
     * @param string $since UTC `Y-m-d H:i:s`.
     * @param int $limit
     * @return int[] Product ids, most recently arrived first.
     */
    public function getRecentArrivals(string $since, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['product_id'])
            // The product id is the tie-break: a bulk import stamps hundreds of rows with the same
            // second, and without it the ranking — and therefore every position in the category —
            // would shuffle on each run.
            ->order(['arrived_at DESC', 'product_id ASC'])
            ->where('arrived_at >= ?', $since)
            ->limit($limit);

        return array_map('intval', $connection->fetchCol($select));
    }
}
