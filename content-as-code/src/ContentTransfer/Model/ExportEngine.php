<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model;

use Magento\Framework\Exception\LocalizedException;
use Scr1be\ContentTransfer\Model\Bundle\Manifest;

/**
 * Turns a selection into a bundle.
 *
 * Two ordering decisions live here and nowhere else, because they are what makes a captured file
 * diffable:
 *
 * 1. **Porters run in dependency order.** Not because capture needs it — capture has no ordering
 *    constraint at all — but because the same order is what import needs, and a bundle whose file
 *    order matches its apply order is one less thing to reason about when reading it.
 * 2. **Entries are sorted by identifier inside each porter.** Collection order comes from whatever
 *    the database felt like returning, so without this a re-capture of untouched content shuffles
 *    the file and the diff is noise.
 */
class ExportEngine
{
    public function __construct(
        private readonly PorterPool $porterPool,
        private readonly StoreScope $storeScope
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function capture(Selection $selection): Bundle
    {
        $entries = [];
        $counts = [];

        foreach ($this->porterPool->getSorted() as $porter) {
            $code = $porter->getCode();

            if (!$selection->includesPorter($code)) {
                continue;
            }

            $captured = $porter->capture($selection);

            usort(
                $captured,
                static fn ($left, $right): int => strcmp($left->getIdentifier(), $right->getIdentifier())
            );

            $counts[$code] = count($captured);

            foreach ($captured as $entry) {
                $entries[] = $entry;
            }
        }

        return new Bundle(
            Manifest::forCapture($this->storeScope->toCodes($selection->getStoreIds()), $counts),
            $entries
        );
    }
}
