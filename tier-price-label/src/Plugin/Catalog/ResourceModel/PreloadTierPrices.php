<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\Plugin\Catalog\ResourceModel;

use Magento\Catalog\Model\ResourceModel\Product\Collection;

/**
 * Executes the bulk tier-price load for collections the listing observer flagged.
 *
 * `after` (not `around`/`before`) because the whole point is to run once the collection has
 * its rows: core's addTierPriceData() reads $collection->getItems(), and the only moment where
 * that set is both complete and paginated is immediately after load() returns.
 *
 * The flag gate is what keeps this cheap. The plugin is attached to the product collection
 * class, so it is entered by every storefront product collection there is; for all of them
 * except the flagged listing collections the body is two getFlag() calls and a return.
 */
class PreloadTierPrices
{
    /**
     * Set by Scr1be\TierPriceLabel\Observer\FlagListingCollection.
     */
    public const PRELOAD_FLAG = 'scr1be_tier_price_preload';

    /**
     * Core's own guard flag, set by addTierPriceData()/addTierPriceDataByGroupId(). Checked
     * here so a collection that already carries tier prices (a themed block that asked for
     * them explicitly) is never queried twice.
     */
    private const CORE_TIER_PRICE_FLAG = 'tier_price_added';

    /**
     * @param Collection $subject
     * @param Collection $result
     * @return Collection
     */
    public function afterLoad(Collection $subject, $result)
    {
        if (!$subject->getFlag(self::PRELOAD_FLAG) || $subject->getFlag(self::CORE_TIER_PRICE_FLAG)) {
            return $result;
        }

        // Clear the request before doing the work, not after, because addTierPriceData() re-enters
        // this method. It opens with getItems(), getItems() calls load(), and load() reaches the
        // generated interceptor like any other public call — so its plugins run again even though
        // the collection is already loaded and the body returns immediately. Core's own
        // `tier_price_added` flag cannot break that cycle: addTierPriceData() sets it on the last
        // line, which the recursion never reaches. Without this line the second page render is a
        // stack overflow, not a slow query.
        $subject->setFlag(self::PRELOAD_FLAG, false);

        // One SELECT over catalog_product_entity_tier_price for the whole page, instead of the
        // per-product afterLoad() the tier-price backend fires the first time a card asks for
        // its ladder.
        $subject->addTierPriceData();

        return $result;
    }
}
