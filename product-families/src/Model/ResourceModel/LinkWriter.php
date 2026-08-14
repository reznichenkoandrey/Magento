<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Scr1be\ProductFamilies\Model\LinkPlan;

/**
 * The only class in the module that writes to `catalog_product_link`, and the reason the reconcile
 * can run over a whole catalogue.
 *
 * The alternative is the service contract, and it is worth being precise about what it costs.
 * `Magento\Catalog\Model\ResourceModel\Product\Link::saveProductLinks()` reads the product's current
 * links, then in `prepareProductLinksData()` loops over the new ones and, for each link that does
 * not already exist, issues `$connection->insert()` followed by `$connection->lastInsertId()` —
 * one round trip per link, before the position rows. Reconciling ten thousand products with twelve
 * links each through that path is well over a hundred thousand statements, one product at a time.
 * Here the same work is 240 inserts, 20 read-back selects (one per 500 *product* ids) and 240
 * position upserts.
 *
 * Everything is chunked at {@see self::BATCH_SIZE}, which bounds the size of a single statement's
 * payload rather than the size of the run.
 */
class LinkWriter
{
    /**
     * Rows per statement. Five hundred three-column rows is a packet MySQL is comfortable with under
     * a default `max_allowed_packet`, and small enough that a failure leaves behind something a
     * person can reason about.
     */
    public const BATCH_SIZE = 500;

    private const LINK_TABLE = 'catalog_product_link';
    private const LINK_ATTRIBUTE_INT_TABLE = 'catalog_product_link_attribute_int';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LinkPositionAttribute $positionAttribute
    ) {
    }

    /**
     * The current contents of one link type, keyed the same way the desired state is, so the planner
     * can diff two arrays of the same shape.
     *
     * The join is LEFT: a link row whose position row is missing — which is what a link created
     * before this module's attribute existed looks like — reads as position zero and gets corrected
     * on the next run rather than being skipped.
     *
     * @return array<int, array<int, array{link_id: int, position: int}>>
     */
    public function readCurrent(int $linkTypeId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                ['l' => $this->resource->getTableName(self::LINK_TABLE)],
                ['link_id', 'product_id', 'linked_product_id']
            )
            ->joinLeft(
                ['p' => $this->resource->getTableName(self::LINK_ATTRIBUTE_INT_TABLE)],
                sprintf(
                    'p.link_id = l.link_id AND p.product_link_attribute_id = %d',
                    $this->positionAttribute->getId($linkTypeId)
                ),
                ['position' => 'p.value']
            )
            ->where('l.link_type_id = ?', $linkTypeId);

        $current = [];
        foreach ($connection->fetchAll($select) as $row) {
            $current[(int)$row['product_id']][(int)$row['linked_product_id']] = [
                'link_id' => (int)$row['link_id'],
                'position' => (int)$row['position'],
            ];
        }

        return $current;
    }

    /**
     * Executes a plan. Order matters: inserts first so the new link ids exist, then the position
     * upsert for inserts and updates together, then the deletes.
     *
     * Deleting last is deliberate. `catalog_product_link_attribute_int` has a foreign key on
     * `link_id` with `onDelete="CASCADE"`, so removing the link rows takes their positions with them
     * and there is no second delete to write — but only if nothing has meanwhile tried to write a
     * position for a link that is already gone.
     */
    public function apply(LinkPlan $plan, int $linkTypeId): void
    {
        $connection = $this->resource->getConnection();
        $linkTable = $this->resource->getTableName(self::LINK_TABLE);

        foreach (array_chunk($plan->getInserts(), self::BATCH_SIZE) as $chunk) {
            $rows = [];
            foreach ($chunk as $insert) {
                $rows[] = [
                    'product_id' => $insert['product_id'],
                    'linked_product_id' => $insert['linked_product_id'],
                    'link_type_id' => $linkTypeId,
                ];
            }
            $connection->insertMultiple($linkTable, $rows);
        }

        $positions = $plan->getInserts() !== []
            ? $this->resolveInsertedPositions($plan->getInserts(), $linkTypeId)
            : [];

        $positionAttributeId = $this->positionAttribute->getId($linkTypeId);
        foreach ($plan->getUpdates() as $update) {
            $positions[] = [
                'product_link_attribute_id' => $positionAttributeId,
                'link_id' => $update['link_id'],
                'value' => $update['position'],
            ];
        }

        foreach (array_chunk($positions, self::BATCH_SIZE) as $chunk) {
            // `catalog_product_link_attribute_int` carries a unique key over
            // (product_link_attribute_id, link_id), so the upsert updates in place instead of
            // growing a second value row for the same link.
            $connection->insertOnDuplicate(
                $this->resource->getTableName(self::LINK_ATTRIBUTE_INT_TABLE),
                $chunk,
                ['value']
            );
        }

        foreach (array_chunk($plan->getDeletes(), self::BATCH_SIZE) as $chunk) {
            $connection->delete($linkTable, ['link_id IN (?)' => $chunk]);
        }
    }

    /**
     * Reads back the ids MySQL assigned to the links just inserted.
     *
     * There is no way around a second read: `lastInsertId()` after a multi-row insert reports the id
     * of the *first* row, and relying on the rest being contiguous means relying on
     * `innodb_autoinc_lock_mode` and on nothing else inserting concurrently. One indexed SELECT per
     * batch of product ids is cheaper than being wrong.
     *
     * @param array<int, array{product_id: int, linked_product_id: int, position: int}> $inserts
     * @return array<int, array{product_link_attribute_id: int, link_id: int, value: int}>
     */
    private function resolveInsertedPositions(array $inserts, int $linkTypeId): array
    {
        $wanted = [];
        $productIds = [];
        foreach ($inserts as $insert) {
            $wanted[$insert['product_id']][$insert['linked_product_id']] = $insert['position'];
            $productIds[$insert['product_id']] = $insert['product_id'];
        }

        $connection = $this->resource->getConnection();
        $attributeId = $this->positionAttribute->getId($linkTypeId);
        $rows = [];

        foreach (array_chunk(array_values($productIds), self::BATCH_SIZE) as $chunk) {
            $select = $connection->select()
                ->from(
                    $this->resource->getTableName(self::LINK_TABLE),
                    ['link_id', 'product_id', 'linked_product_id']
                )
                ->where('link_type_id = ?', $linkTypeId)
                ->where('product_id IN (?)', $chunk);

            foreach ($connection->fetchAll($select) as $row) {
                $position = $wanted[(int)$row['product_id']][(int)$row['linked_product_id']] ?? null;
                if ($position === null) {
                    continue;
                }
                $rows[] = [
                    'product_link_attribute_id' => $attributeId,
                    'link_id' => (int)$row['link_id'],
                    'value' => $position,
                ];
            }
        }

        return $rows;
    }
}
