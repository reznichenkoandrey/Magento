<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;

/**
 * The subtree walk.
 *
 * Magento stores a category's ancestry as a materialised path ("1/2/20/22"), which turns "every
 * descendant of this category" into a prefix match — one indexed range scan on
 * catalog_category_entity.path, no recursion, no per-level round trip. Paths are digits and
 * slashes only, so the LIKE pattern needs no escaping.
 *
 * Everything here is store-scoped, and that matters: is_active is a store-scoped attribute, so a
 * child's *effective* state depends on which store view is asking. Loading the collection with a
 * store id makes the EAV layer resolve the default row against the store override, which is the
 * same answer the storefront would give.
 */
class SubtreeLocator
{
    private const ATTRIBUTE_IS_ACTIVE = 'is_active';
    private const ATTRIBUTE_LEVEL = 'level';
    private const ATTRIBUTE_PATH = 'path';
    private const PATH_WILDCARD = '/%';

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * Descendants only — the exact path is not matched by "<path>/%", so the saved category is
     * never in its own subtree. Level order so a log line reads top-down.
     *
     * @return Category[]
     */
    public function loadDescendants(string $path, int $storeId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect(self::ATTRIBUTE_IS_ACTIVE);
        $collection->addAttributeToFilter(self::ATTRIBUTE_PATH, ['like' => $path . self::PATH_WILDCARD]);
        $collection->addAttributeToSort(self::ATTRIBUTE_LEVEL, 'ASC');

        return array_values($collection->getItems());
    }

    /**
     * Used by the admin prompt, which needs the number before anything is written. getSize() runs
     * a COUNT over the same filtered select rather than loading the models.
     */
    public function countActiveDescendants(string $path, int $storeId): int
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToFilter(self::ATTRIBUTE_PATH, ['like' => $path . self::PATH_WILDCARD]);
        $collection->addAttributeToFilter(self::ATTRIBUTE_IS_ACTIVE, 1);

        return (int) $collection->getSize();
    }
}
