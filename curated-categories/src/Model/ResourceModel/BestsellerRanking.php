<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;

/**
 * The bestsellers ranking: one grouped query over the order tables, no aggregation table of its own.
 *
 * Magento ships `sales_bestsellers_aggregated_*`, and this deliberately does not use it. Those
 * tables are built by the sales reports cron, are keyed by period and store, and rank by
 * `qty_ordered` — an order placed and cancelled the same afternoon counts. For a page customers
 * shop from, the number that matters is what people kept, so the ranking nets cancellations and
 * refunds out of the ordered quantity and runs against the orders themselves.
 *
 * Three details in the WHERE are the whole correctness of it:
 *
 * - **Paid states only.** `pending_payment` is a shopper who reached the payment page; `canceled` is
 *   a sale that did not happen; `closed` is one that was fully refunded. None of them is evidence
 *   that anyone wants the product.
 * - **`parent_item_id IS NULL`.** A configurable order writes two rows — the configurable and the
 *   simple child it resolved to — and only the parent carries the category assignment. Counting the
 *   child would rank a product that never appears in a listing.
 * - **No store filter.** `catalog_category_product` has no store column, so membership is global and
 *   the ranking behind it has to be too. A per-store bestsellers list is a real feature, and it is
 *   not one this table can store.
 */
class BestsellerRanking
{
    private const ORDER_TABLE = 'sales_order';
    private const ORDER_ITEM_TABLE = 'sales_order_item';

    /**
     * @see Order::STATE_PROCESSING
     * @see Order::STATE_COMPLETE
     */
    private const PAID_STATES = [Order::STATE_PROCESSING, Order::STATE_COMPLETE];

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * @param string $since UTC `Y-m-d H:i:s`; `sales_order.created_at` is a UTC timestamp column.
     * @param int $limit
     * @return int[] Product ids, best-selling first.
     */
    public function getTopProductIds(string $since, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        // COALESCE rather than trusting the columns: qty_canceled and qty_refunded are nullable, and
        // one NULL would turn the whole subtraction into NULL and drop the product out of the ranking
        // entirely — a silent hole rather than a wrong number.
        $netQuantity = new \Zend_Db_Expr(
            'SUM(oi.qty_ordered - COALESCE(oi.qty_canceled, 0) - COALESCE(oi.qty_refunded, 0))'
        );

        $select = $connection->select()
            ->from(['oi' => $this->resourceConnection->getTableName(self::ORDER_ITEM_TABLE)], [])
            ->join(
                ['o' => $this->resourceConnection->getTableName(self::ORDER_TABLE)],
                'o.entity_id = oi.order_id',
                []
            )
            ->columns(['product_id' => 'oi.product_id', 'net_qty' => $netQuantity])
            ->where('o.created_at >= ?', $since)
            ->where('o.state IN (?)', self::PAID_STATES)
            ->where('oi.parent_item_id IS NULL')
            ->where('oi.product_id IS NOT NULL')
            ->group('oi.product_id')
            ->having('net_qty > 0')
            // The product id is the tie-break so that two products with identical sales do not swap
            // places between runs, which would rewrite positions and churn the changelog for nothing.
            ->order(['net_qty DESC', 'product_id ASC'])
            ->limit($limit);

        return array_map('intval', $connection->fetchCol($select));
    }
}
