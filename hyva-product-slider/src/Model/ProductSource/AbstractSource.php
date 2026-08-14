<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ProductSource;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Scr1be\HyvaProductSlider\Api\ProductSourceInterface;

/**
 * What every source shares: it is available, it needs no argument, and it never invents an id.
 *
 * The two defaults are the common case (six of the nine sources); the three that do take an argument
 * override `validateSourceValue()` and say what they accept.
 */
abstract class AbstractSource implements ProductSourceInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function validateSourceValue(?string $sourceValue): void
    {
        // Most sources are argument-free: "new products" needs no parameter to mean what it means.
    }

    /**
     * Ids out of a loaded EAV collection, in select order.
     *
     * Not `getAllIds()`, and the difference is the whole reason this helper exists:
     * `Magento\Eav\Model\Entity\Collection\AbstractCollection::_getAllIdsSelect()` calls
     * `$idsSelect->reset(Select::ORDER)` before applying the limit, so a collection sorted by
     * `news_from_date DESC` hands back an arbitrary slice of the matching set rather than the newest
     * of it. A source whose entire contract is "ids, most relevant first" cannot use a method that
     * throws the sort away.
     *
     * @return int[]
     */
    protected function readIdsInOrder(ProductCollection $collection): array
    {
        $collection->load();

        return array_values(array_map('intval', $collection->getColumnValues('entity_id')));
    }
}
