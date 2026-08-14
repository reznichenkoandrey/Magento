<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Every read and write this module makes against the category-product pivot.
 *
 * Four methods, and the reason they are raw SQL rather than the catalog service contracts is cost,
 * not taste. `Magento\Catalog\Model\CategoryLinkRepository::save()` loads the category through
 * `CategoryRepositoryInterface`, loads the product through `ProductRepositoryInterface`, adds one
 * entry to `getProductsPosition()` and then calls `$category->save()` — a full category save per
 * link, with `deleteByIds()` doing the same per removal. The other door,
 * `CategoryLinkManagement::assignProductToCategories()`, is keyed by product and replaces that
 * product's entire category list, which would strip a curated category's members out of every other
 * category they belong to.
 *
 * The pivot itself is four integers with a composite primary key on
 * (entity_id, category_id, product_id); reconciling it is an upsert and a delete, and nothing about
 * a merchandising feed needs more than that.
 *
 * What the module gives up by bypassing the repository is spelled out in the README: no
 * `catalog_category_save_*` events, and no cache invalidation of its own. Both are deliberate — the
 * pivot is an mview-subscribed table, so the changelog picks the write up either way.
 */
class CategoryMembership
{
    public const TABLE = 'catalog_category_product';
    private const PRODUCT_TABLE = 'catalog_product_entity';

    /**
     * MySQL builds one packet per statement; an unbounded `IN (…)` on a large feed is how a
     * reconcile meets `max_allowed_packet`. Five hundred rows keeps every statement small enough to
     * be uninteresting and still turns a 10,000-product feed into twenty statements rather than
     * 10,000.
     */
    private const WRITE_BATCH_SIZE = 500;

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    /**
     * @return array<int, int> productId => position, for everything currently in the category.
     */
    public function getMembership(int $categoryId): array
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['product_id', 'position'])
            ->where('category_id = ?', $categoryId);

        $membership = [];
        foreach ($connection->fetchAll($select) as $row) {
            $membership[(int) $row['product_id']] = (int) $row['position'];
        }

        return $membership;
    }

    /**
     * Insert the rows that are missing and rewrite the position of the ones that are not.
     *
     * `insertOnDuplicate` with `position` as the sole update column is what makes a re-rank free:
     * a product that is already a member costs an update of one integer instead of a delete and an
     * insert, which would churn the primary key and, on a table two other indexers subscribe to,
     * put the same product in the changelog twice.
     *
     * @param array<int, int> $positionsByProductId productId => position
     * @return int Rows the statements reported as affected.
     */
    public function upsert(int $categoryId, array $positionsByProductId): int
    {
        if ($positionsByProductId === []) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $rows = [];
        foreach ($positionsByProductId as $productId => $position) {
            $rows[] = [
                'category_id' => $categoryId,
                'product_id' => (int) $productId,
                'position' => (int) $position,
            ];
        }

        $affected = 0;
        foreach (array_chunk($rows, self::WRITE_BATCH_SIZE) as $chunk) {
            $affected += (int) $connection->insertOnDuplicate($table, $chunk, ['position']);
        }

        return $affected;
    }

    /**
     * @param int[] $productIds
     * @return int Rows deleted.
     */
    public function remove(int $categoryId, array $productIds): int
    {
        if ($productIds === []) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $deleted = 0;
        foreach (array_chunk(array_values($productIds), self::WRITE_BATCH_SIZE) as $chunk) {
            $deleted += (int) $connection->delete(
                $table,
                [
                    'category_id = ?' => $categoryId,
                    'product_id IN (?)' => $chunk,
                ]
            );
        }

        return $deleted;
    }

    /**
     * Drop ids that have no product row, preserving the caller's ranking.
     *
     * `catalog_category_product.product_id` carries a foreign key onto `catalog_product_entity`, so
     * one stale id in a feed does not skip a row — it aborts the whole upsert. Sources read from
     * order history and from this module's own arrival log, both of which outlive the products they
     * name, so the check is not hypothetical.
     *
     * @param int[] $productIds
     * @return int[] The same ids, same order, minus the ones that no longer exist.
     */
    public function filterExistingProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        $existing = [];
        foreach (array_chunk(array_values($productIds), self::WRITE_BATCH_SIZE) as $chunk) {
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName(self::PRODUCT_TABLE), ['entity_id'])
                ->where('entity_id IN (?)', $chunk);

            foreach ($connection->fetchCol($select) as $id) {
                $existing[(int) $id] = true;
            }
        }

        return array_values(
            array_filter($productIds, static fn (int $productId): bool => isset($existing[$productId]))
        );
    }
}
