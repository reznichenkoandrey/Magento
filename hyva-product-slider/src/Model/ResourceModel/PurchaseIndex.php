<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;

/**
 * The 15-minute purchase index: one row per (store, product) bought inside the window.
 *
 * Two features read it — the Recently Bought source and the social-proof line — and both would
 * otherwise be a `GROUP BY` across `sales_order_item`, `sales_order` and `sales_order_address` on
 * every render of a page that is supposed to come out of cache. Denormalising once every fifteen
 * minutes turns both into a primary-key lookup.
 *
 * The rebuild is deliberately two statements and no PHP loop, so its cost scales with the *window*
 * rather than with the shop:
 *
 * 1. An aggregate `INSERT … ON DUPLICATE KEY UPDATE` that recomputes `last_ordered_at` and
 *    `purchases` for everything sold in the window.
 * 2. An `UPDATE … JOIN` that stamps the buyer of that most recent order onto the row.
 *
 * Then one `DELETE` drops rows whose newest order has aged out, which is what makes the index shrink
 * as well as grow.
 */
class PurchaseIndex
{
    public const TABLE = 'scr1be_slider_purchase';

    /**
     * What counts as a purchase.
     *
     * Paid states only: a `pending_payment` order is a shopper who reached the payment page, and a
     * `canceled` one is a sale that did not happen. Neither belongs in "12 people bought this today".
     * `closed` — fully refunded — is excluded for the same reason.
     */
    private const PAID_STATES = [Order::STATE_PROCESSING, Order::STATE_COMPLETE];

    /**
     * `buyer_name` and `buyer_city` are 64 characters; the order columns are 255. Truncating in SQL
     * rather than trusting the data keeps the rebuild from aborting on one long city name under
     * MySQL's strict mode.
     */
    private const NAME_LENGTH = 64;

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * @param string $since UTC `Y-m-d H:i:s`. Orders older than this are neither indexed nor kept.
     * @return int Rows the index holds afterwards.
     */
    public function rebuild(string $since): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->query($this->buildAggregateQuery($since, $table));
        $connection->query($this->buildBuyerQuery($table));
        $connection->delete($table, ['last_ordered_at < ?' => $since]);

        return (int) $connection->fetchOne(
            $connection->select()->from($table, new \Zend_Db_Expr('COUNT(*)'))
        );
    }

    /**
     * @return int[] Product ids, most recently bought first.
     */
    public function getRecentProductIds(int $storeId, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['p' => $this->resourceConnection->getTableName(self::TABLE)], ['product_id'])
            ->where('p.store_id = ?', $storeId)
            ->order('p.last_ordered_at DESC')
            ->limit($limit);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * The rows behind the social-proof line, keyed by product id.
     *
     * @param int[] $productIds
     * @param string $since UTC `Y-m-d H:i:s` — the social-proof window, which is normally much
     *                      shorter than the index window.
     * @return array<int, array{last_ordered_at: string, purchases: int, buyer_name: ?string, buyer_city: ?string}>
     */
    public function getPurchases(int $storeId, array $productIds, string $since): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if ($productIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                ['p' => $this->resourceConnection->getTableName(self::TABLE)],
                ['product_id', 'last_ordered_at', 'purchases', 'buyer_name', 'buyer_city']
            )
            ->where('p.store_id = ?', $storeId)
            ->where('p.product_id IN (?)', $productIds)
            ->where('p.last_ordered_at >= ?', $since);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[(int) $row['product_id']] = [
                'last_ordered_at' => (string) $row['last_ordered_at'],
                'purchases' => (int) $row['purchases'],
                'buyer_name' => $row['buyer_name'] === null ? null : (string) $row['buyer_name'],
                'buyer_city' => $row['buyer_city'] === null ? null : (string) $row['buyer_city'],
            ];
        }

        return $rows;
    }

    /**
     * `parent_item_id IS NULL` keeps the row the shopper actually chose.
     *
     * A configurable sale writes two `sales_order_item` rows: the configurable, and the simple child
     * carrying `parent_item_id`. The configurable is the one with a listing page, a card and a URL,
     * so it is the one a carousel can show — indexing the child would fill the slider with products
     * whose visibility is "Not Visible Individually".
     */
    private function buildAggregateQuery(string $since, string $table): string
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['i' => $this->resourceConnection->getTableName('sales_order_item')], [])
            ->join(
                ['o' => $this->resourceConnection->getTableName('sales_order')],
                'o.entity_id = i.order_id',
                []
            )
            ->where('o.created_at >= ?', $since)
            ->where('o.state IN (?)', self::PAID_STATES)
            ->where('o.store_id IS NOT NULL')
            ->where('i.parent_item_id IS NULL')
            ->where('i.product_id IS NOT NULL')
            ->columns(
                [
                    'store_id' => 'o.store_id',
                    'product_id' => 'i.product_id',
                    'last_ordered_at' => new \Zend_Db_Expr('MAX(o.created_at)'),
                    'purchases' => new \Zend_Db_Expr('COUNT(DISTINCT i.order_id)'),
                ]
            )
            ->group(['o.store_id', 'i.product_id']);

        return $connection->insertFromSelect(
            $select,
            $table,
            ['store_id', 'product_id', 'last_ordered_at', 'purchases'],
            AdapterInterface::INSERT_ON_DUPLICATE
        );
    }

    /**
     * Stamps the most recent buyer onto each row.
     *
     * The join back on `o.created_at = p.last_ordered_at` is what makes "most recent" mean the same
     * thing here as in the aggregate above. Two orders for the same product in the same second on the
     * same store would leave the choice to the engine; showing either one is correct.
     *
     * `updateFromSelect()` takes a select with no `from()` — its joins reference the update target's
     * alias, and its columns become the `SET` pairs. That is the shape core uses in
     * `Magento\CatalogRule\Model\Indexer\ProductPriceIndexModifier::modifyPrice()`.
     */
    private function buildBuyerQuery(string $table): string
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->join(
                ['o' => $this->resourceConnection->getTableName('sales_order')],
                'o.store_id = p.store_id AND o.created_at = p.last_ordered_at',
                []
            )
            ->join(
                ['i' => $this->resourceConnection->getTableName('sales_order_item')],
                'i.order_id = o.entity_id AND i.product_id = p.product_id AND i.parent_item_id IS NULL',
                []
            )
            ->joinLeft(
                ['a' => $this->resourceConnection->getTableName('sales_order_address')],
                $connection->quoteInto(
                    'a.parent_id = o.entity_id AND a.address_type = ?',
                    OrderAddress::TYPE_BILLING
                ),
                []
            )
            ->columns(
                [
                    'buyer_name' => new \Zend_Db_Expr(
                        sprintf('SUBSTRING(o.customer_firstname, 1, %d)', self::NAME_LENGTH)
                    ),
                    'buyer_city' => new \Zend_Db_Expr(
                        sprintf('SUBSTRING(a.city, 1, %d)', self::NAME_LENGTH)
                    ),
                ]
            );

        return $connection->updateFromSelect($select, ['p' => $table]);
    }
}
