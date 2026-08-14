<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\Data\Collection as DataCollection;

/**
 * The set of root categories a store view could be shown, loaded once per request.
 *
 * A "menu" in this module is a root category and the subtree beneath it. That is not a shortcut
 * around building a menu entity — it is the observation that Magento already ships one. Root
 * categories are exactly the level-1 nodes of the category tree (core's own
 * `Collection::addRootLevelFilter()` defines them as `path != '1'` and `level <= 1`), a store
 * group already points at one, and the admin already has a tree editor for them.
 *
 * The resolver needs three things from this class and each of them is answered from the same
 * single query: is this id a root category, is it active, and which active root comes first.
 * Loading them all costs one indexed query over a table that holds a handful of rows on nearly
 * every installation, which is why there is no per-id lookup path.
 */
class RootCategories
{
    /**
     * @var array<int, array<int, array{id: int, name: string, is_active: bool}>>
     */
    private array $perStore = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function isActiveRoot(int $categoryId, int $storeId): bool
    {
        $roots = $this->load($storeId);

        return isset($roots[$categoryId]) && $roots[$categoryId]['is_active'];
    }

    /**
     * The first active root category in admin sort order, or null when the store has none.
     *
     * "First" means the order the admin sees in the category tree — position, then id as the
     * tie-break Magento itself falls back on when positions were never set.
     */
    public function getFirstActiveRootId(int $storeId): ?int
    {
        foreach ($this->load($storeId) as $root) {
            if ($root['is_active']) {
                return $root['id'];
            }
        }

        return null;
    }

    /**
     * @return array<int, array{id: int, name: string, is_active: bool}>
     */
    private function load(int $storeId): array
    {
        if (isset($this->perStore[$storeId])) {
            return $this->perStore[$storeId];
        }

        /** @var Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        // is_active is selected rather than filtered: the resolver has to be able to tell "this
        // root exists but is switched off" from "this root does not exist", because only the
        // first of those is worth mentioning in a log or a demo.
        $collection->addAttributeToSelect(['name', 'is_active']);
        $collection->addRootLevelFilter();
        $collection->addOrder('position', DataCollection::SORT_ORDER_ASC);
        $collection->addOrder('entity_id', DataCollection::SORT_ORDER_ASC);

        $roots = [];

        /** @var Category $category */
        foreach ($collection as $category) {
            $id = (int) $category->getId();
            $roots[$id] = [
                'id' => $id,
                'name' => (string) $category->getName(),
                'is_active' => (bool) $category->getIsActive(),
            ];
        }

        return $this->perStore[$storeId] = $roots;
    }
}
