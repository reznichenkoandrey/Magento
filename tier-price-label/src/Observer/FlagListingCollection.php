<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\Observer;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Scr1be\TierPriceLabel\Plugin\Catalog\ResourceModel\PreloadTierPrices;

/**
 * Marks product-listing collections for the bulk tier-price load.
 *
 * `catalog_block_product_list_collection` is the right event to *select* collections — it is
 * dispatched by exactly the blocks that render grids of product cards — but it is the wrong
 * moment to *load* them. It fires from ListProduct::initializeProductCollection(), before
 * _beforeToHtml() hands the collection to the toolbar, so at this point the collection has no
 * page size: calling addTierPriceData() here would trigger a load of the entire category
 * (2,040 rows on Luma sample data) instead of the 24 rows on screen.
 *
 * Hence the split. The observer decides *which* collections deserve preloading; the paired
 * afterLoad plugin decides *when* — right after the paginated load, where the item set is
 * final.
 */
class FlagListingCollection implements ObserverInterface
{
    private const EVENT_DATA_COLLECTION = 'collection';

    public function execute(Observer $observer): void
    {
        $collection = $observer->getData(self::EVENT_DATA_COLLECTION);

        // Widgets and third-party listing blocks reuse this event with their own collection
        // types; only the catalog product collection carries the bulk tier-price loader.
        if (!$collection instanceof Collection) {
            return;
        }

        $collection->setFlag(PreloadTierPrices::PRELOAD_FLAG, true);
    }
}
