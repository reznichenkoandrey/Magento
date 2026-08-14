<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ProductSource;

use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Model\ResourceModel\PurchaseIndex;

/**
 * What other people just bought, newest purchase first.
 *
 * It reads the module's own index instead of the order tables, and that is the whole design. The
 * honest version of this query is a join across `sales_order_item`, `sales_order` and
 * `sales_order_address` with a `GROUP BY` and a `MAX()`, on the two largest tables in the database,
 * on a page that is otherwise served from cache. Running it per render is how a carousel becomes the
 * slowest thing on a home page.
 *
 * A 15-minute cron writes one row per (store, product) instead, and this source reads a covering
 * index. The cost is staleness bounded by the cron interval — for "somebody bought this recently",
 * fifteen minutes is not a lie.
 */
class RecentlyBought extends AbstractSource
{
    public const CODE = 'recently_bought';

    public function __construct(private readonly PurchaseIndex $purchaseIndex)
    {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string) __('Recently Bought');
    }

    /**
     * @return int[]
     */
    public function getProductIds(SliderInterface $slider, int $storeId, int $limit): array
    {
        return $this->purchaseIndex->getRecentProductIds($storeId, $limit);
    }
}
