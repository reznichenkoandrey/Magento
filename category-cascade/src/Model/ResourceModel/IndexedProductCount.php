<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Model\ResourceModel;

use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Store;

/**
 * One grouped query against catalog_category_product_index_store<id>.
 *
 * The index table already encodes the rule core recomputes per category: an anchor category has a
 * row for every product below it, a non-anchor category only for its direct assignments. Counting
 * rows per category_id therefore reproduces core's anchor/regular split for free — the difference
 * is that core asks per anchor category and this asks once for the whole tree.
 *
 * The number is not identical to core's, and the difference is deliberate. Core counts rows in the
 * catalog_category_product pivot: assignments, including disabled products, products not assigned
 * to the store's website and products that are not visible in the catalog. The index counts what
 * the storefront would actually list. For an admin looking at a tree of one store view, the second
 * number is the one worth having, and it is the reason this reads the index rather than a cheaper
 * grouped query over the pivot.
 */
class IndexedProductCount
{
    private const COLUMN_CATEGORY_ID = 'category_id';
    private const COLUMN_PRODUCT_COUNT = 'product_count';

    /**
     * The visibilities a category listing renders. Spelled out from the core constants rather than
     * taken from Visibility's helper, which is a static method on an injectable model — calling it
     * through an instance works and reads like a mistake.
     */
    private const VISIBLE_IN_CATALOG = [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly TableMaintainer $tableMaintainer
    ) {
    }

    /**
     * There is no index table for the admin scope, and a store view whose index has never been
     * built has no table either — a freshly installed store, or one whose tables were dropped by
     * a failed reindex.
     */
    public function isAvailable(int $storeId): bool
    {
        if ($storeId === Store::DEFAULT_STORE_ID) {
            return false;
        }

        try {
            $table = $this->tableMaintainer->getMainTable($storeId);
        } catch (\Throwable) {
            return false;
        }

        return $this->resourceConnection->getConnection()->isTableExists($table);
    }

    /**
     * @param int[] $categoryIds
     * @return array<int, int> category id => product count, missing for categories with no rows
     */
    public function fetch(int $storeId, array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->tableMaintainer->getMainTable($storeId),
                [
                    self::COLUMN_CATEGORY_ID,
                    // Parenthesised column values are treated as expressions by the select
                    // builder, which is how core writes its own aggregate columns.
                    self::COLUMN_PRODUCT_COUNT => 'COUNT(product_id)',
                ]
            )
            ->where(self::COLUMN_CATEGORY_ID . ' IN (?)', $categoryIds)
            ->where('visibility IN (?)', self::VISIBLE_IN_CATALOG)
            ->group(self::COLUMN_CATEGORY_ID);

        return array_map('intval', $connection->fetchPairs($select));
    }
}
