<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ProductSource;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Model\Config;

/**
 * Ranked by quantity sold, out of Magento's own aggregated bestsellers report.
 *
 * The report is used rather than a fresh `GROUP BY` over `sales_order_item` because it already
 * exists, it is already indexed by period and store, and Magento already keeps it current — the
 * statistics cron refreshes it. Re-deriving the same ranking on every page render would be a second,
 * slower answer to a question the platform has answered.
 *
 * The `store_id = ?` filter is not cosmetic. `Magento\Sales\Model\ResourceModel\Report\Bestsellers::
 * aggregate()` first writes one row per real store and then re-selects those rows
 * `WHERE store_id <> Store::DEFAULT_STORE_ID`, grouped by period and product, to insert an
 * all-stores roll-up under `store_id = 0`. Summing without the filter would count every sale twice.
 *
 * The trade-off, stated plainly: this source is only as fresh as the last statistics refresh. On a
 * shop that never runs it the table is empty and the slider is empty with it — which is why the
 * README says to check Reports → Refresh Statistics before calling it broken.
 */
class Bestsellers extends AbstractSource
{
    public const CODE = 'bestsellers';

    private const TABLE = 'sales_bestsellers_aggregated_daily';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly TimezoneInterface $localeDate,
        private readonly Config $config
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string) __('Bestsellers');
    }

    /**
     * @return int[]
     */
    public function getProductIds(SliderInterface $slider, int $storeId, int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();

        // `period` is a DATE column, so the boundary is a day in the store's calendar, not an instant.
        $since = $this->localeDate->date()
            ->modify(sprintf('-%d days', $this->config->getBestsellersWindowDays($storeId)))
            ->format('Y-m-d');

        $select = $connection->select()
            ->from(['b' => $this->resourceConnection->getTableName(self::TABLE)], ['product_id'])
            ->where('b.store_id = ?', $storeId)
            ->where('b.period >= ?', $since)
            ->where('b.product_id IS NOT NULL')
            ->group('b.product_id')
            ->order(new \Zend_Db_Expr('SUM(b.qty_ordered) DESC'))
            ->limit($limit);

        return array_map('intval', $connection->fetchCol($select));
    }
}
