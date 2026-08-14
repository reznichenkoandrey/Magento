<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ProductSource;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Reports\Model\Event as ReportEvent;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Model\Config;

/**
 * Ranked by product-view events inside a window.
 *
 * `report_event` is the only place vanilla Magento records a product view. Its `event_type_id` is a
 * foreign key to `report_event_types`, and the row is seeded with a fixed id rather than an
 * autoincrement one: `Magento\Reports\Setup\Patch\Data\InitializeReportEntityTypesAndPages` calls
 * `insertForce()` with `event_type_id => Event::EVENT_PRODUCT_VIEW` and
 * `event_name => 'catalog_product_view'`. So the constant is safe to filter on, and a join to
 * resolve the name would buy nothing.
 *
 * The rows only exist if somebody is writing them. `Magento\Reports\Observer\
 * CatalogProductViewObserver::execute()` returns immediately unless
 * `ReportStatus::isReportEnabled(Event::EVENT_PRODUCT_VIEW)` is true, which reads two flags —
 * `reports/options/enabled` and `reports/options/product_view_enabled`. This source therefore has
 * two failure modes that look identical from the storefront (empty slider) and are worth naming in
 * the README: the module is off, or the reports are.
 *
 * A view is not an endorsement — the most-viewed product on a shop is sometimes just the one linked
 * from a newsletter — which is why the window is short by default.
 */
class MostViewed extends AbstractSource
{
    public const CODE = 'most_viewed';

    private const MODULE_NAME = 'Magento_Reports';

    private const TABLE = 'report_event';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly TimezoneInterface $localeDate,
        private readonly ModuleManager $moduleManager,
        private readonly Config $config
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string) __('Most Viewed');
    }

    /**
     * Hidden from the admin form entirely when Reports is off, rather than offered and then silently
     * empty. A source that cannot work is worse than one that is not there.
     */
    public function isAvailable(): bool
    {
        return $this->moduleManager->isEnabled(self::MODULE_NAME);
    }

    /**
     * @return int[]
     */
    public function getProductIds(SliderInterface $slider, int $storeId, int $limit): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        // `logged_at` is a TIMESTAMP written in UTC, so the boundary is converted rather than formatted
        // out of the store's clock.
        $since = $this->localeDate->date()
            ->modify(sprintf('-%d days', $this->config->getMostViewedWindowDays($storeId)))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $select = $connection->select()
            ->from(['e' => $this->resourceConnection->getTableName(self::TABLE)], ['object_id'])
            ->where('e.event_type_id = ?', ReportEvent::EVENT_PRODUCT_VIEW)
            ->where('e.store_id = ?', $storeId)
            ->where('e.logged_at >= ?', $since)
            ->group('e.object_id')
            ->order(new \Zend_Db_Expr('COUNT(e.event_id) DESC'))
            ->limit($limit);

        return array_map('intval', $connection->fetchCol($select));
    }
}
