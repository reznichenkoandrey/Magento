<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Store;
use Scr1be\CategoryCascade\Model\ResourceModel\OverrideSweeper;

/**
 * The cascade itself: walk the subtree, write one attribute per child, sweep the per-store
 * overrides, commit once.
 *
 * The single most important property of this class is what it does *not* call. Saving each child
 * through CategoryRepository::save() would work exactly once — the first child's save would
 * dispatch catalog_category_save_commit_after, the observer would run again, and a three-level
 * subtree would re-enter the cascade once per node until the request died. Writing through the
 * resource model's attribute-only save writes the same row without a model save, so no save event
 * is dispatched and there is no recursion path to guard against. There is no re-entrancy flag in
 * this module because there is nothing for one to catch.
 *
 * The cost of that choice is honest: attribute-only writes skip the model's own afterSave work
 * (url rewrites, indexer row updates), so the module has to invalidate what it skipped. That is
 * CascadeInvalidator's whole job.
 */
class SubtreeDisabler
{
    private const ATTRIBUTE_IS_ACTIVE = 'is_active';
    private const VALUE_DISABLED = 0;

    public function __construct(
        private readonly SubtreeLocator $locator,
        private readonly CategoryResource $categoryResource,
        private readonly OverrideSweeper $sweeper,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @throws \Throwable whatever the writes threw, after the transaction is rolled back
     */
    public function disableSubtree(Category $parent): CascadeResult
    {
        $storeId = (int) $parent->getStoreId();
        $descendants = $this->locator->loadDescendants((string) $parent->getPath(), $storeId);
        if ($descendants === []) {
            return new CascadeResult([], [], 0);
        }

        $connection = $this->resourceConnection->getConnection();

        // One transaction for the whole subtree: a half-cascaded tree is worse than an uncascaded
        // one, because the admin has no way of telling which half ran. saveAttribute() opens a
        // transaction of its own, and Magento's adapter reference-counts them — the inner commits
        // only decrement, so the outer commit here is the one that makes anything durable.
        $connection->beginTransaction();
        try {
            $subtreeIds = [];
            $disabledIds = [];

            foreach ($descendants as $child) {
                $subtreeIds[] = (int) $child->getId();

                // Already off in this scope: writing it again would be a pointless row update and
                // would pad the log with categories the merchant disabled themselves.
                if (!(bool) (int) $child->getData(self::ATTRIBUTE_IS_ACTIVE)) {
                    continue;
                }

                $child->setStoreId($storeId);
                $child->setData(self::ATTRIBUTE_IS_ACTIVE, self::VALUE_DISABLED);
                $this->categoryResource->saveAttribute($child, self::ATTRIBUTE_IS_ACTIVE);

                $disabledIds[] = (int) $child->getId();
            }

            // Only meaningful when the parent was saved in the default scope. A cascade run inside
            // a store view writes that store's rows directly, and the other store views are none
            // of its business. The sweep covers the *whole* subtree rather than just the ids that
            // were written, because a child already disabled by default can still carry an
            // enabling override — that child needs no save and does need the sweep.
            $clearedRows = $storeId === Store::DEFAULT_STORE_ID
                ? $this->sweeper->clearEnabledOverrides($subtreeIds)
                : 0;

            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }

        return new CascadeResult($subtreeIds, $disabledIds, $clearedRows);
    }
}
