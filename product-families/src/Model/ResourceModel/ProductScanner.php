<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model\ResourceModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;

/**
 * Stage 1: the full-scan driver.
 *
 * It walks the whole catalogue once per family and yields three columns — product id, group value,
 * variant value. Everything downstream is pure PHP over that stream, which is the reason the rest of
 * the pipeline can be unit tested without a database.
 *
 * Three decisions are baked in here:
 *
 * - **Keyset pagination, not OFFSET.** `WHERE entity_id > ? ORDER BY entity_id LIMIT n` costs the
 *   same on page 200 as on page 1; `LIMIT n OFFSET 200n` does not, and a catalogue big enough to
 *   need this module is big enough to feel it.
 * - **Default scope only.** `catalog_product_link` carries no store column — `Magento_Catalog`'s
 *   `db_schema.xml` gives it `link_id`, `product_id`, `linked_product_id` and `link_type_id` and
 *   nothing else — so a store-scoped override of the group attribute could not be represented in the
 *   output even if it were read. Values come from `store_id = 0` and the module says so rather than
 *   pretending otherwise.
 * - **Enabled and visible products only.** A link to a disabled product is dropped at render time by
 *   the collection anyway, and a link to a not-visible-individually variant points at a page the
 *   storefront will not serve. Filtering at the source keeps both out of the table instead of out of
 *   the page.
 */
class ProductScanner
{
    /**
     * Rows per round trip. Large enough that the per-query overhead disappears against the row cost,
     * small enough that one page of a wide EAV join stays comfortably inside a PHP request's memory.
     */
    public const SCAN_PAGE_SIZE = 2000;

    private const ENTITY_TABLE = 'catalog_product_entity';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @return \Generator<array{entity_id: int, group_value: string, variant_value: string}>
     * @throws LocalizedException when either attribute does not exist
     */
    public function scan(string $groupAttributeCode, string $variantAttributeCode = ''): \Generator
    {
        $groupAttribute = $this->requireAttribute($groupAttributeCode);
        $variantAttribute = $variantAttributeCode !== '' ? $this->requireAttribute($variantAttributeCode) : null;

        $connection = $this->resource->getConnection();
        $entityTable = $this->resource->getTableName(self::ENTITY_TABLE);
        $lastId = 0;

        while (true) {
            $select = $connection->select()
                ->from(['e' => $entityTable], ['entity_id' => 'e.entity_id'])
                ->where('e.entity_id > ?', $lastId)
                ->order('e.entity_id ASC')
                ->limit(self::SCAN_PAGE_SIZE);

            $groupExpression = $this->addValueColumn($select, $groupAttribute, 'group_value', 'grp');

            if ($variantAttribute !== null) {
                $this->addValueColumn($select, $variantAttribute, 'variant_value', 'var');
            } else {
                $select->columns(['variant_value' => new \Zend_Db_Expr("''")]);
            }

            $this->addStorefrontFilters($select);

            // A product with no value for the family key belongs to no family, and letting it
            // through would make every unset product a member of one enormous "" family.
            $select->where(sprintf('%s IS NOT NULL', $groupExpression))
                ->where(sprintf('%s != ?', $groupExpression), '');

            $rows = $connection->fetchAll($select);
            if (!$rows) {
                return;
            }

            foreach ($rows as $row) {
                $lastId = (int)$row['entity_id'];
                yield [
                    'entity_id' => $lastId,
                    'group_value' => (string)$row['group_value'],
                    'variant_value' => (string)($row['variant_value'] ?? ''),
                ];
            }

            if (count($rows) < self::SCAN_PAGE_SIZE) {
                return;
            }
        }
    }

    /**
     * Adds the attribute's value to the select and returns the SQL expression that names it, so the
     * caller can filter on the same expression whichever storage the attribute uses.
     *
     * Static attributes (`sku`, `created_at`, …) are columns on the entity table itself rather than
     * rows in a value table, so they need no join at all. Supporting them is what makes `sku` usable
     * as a family key on a catalogue whose SKUs carry a family prefix.
     */
    private function addValueColumn(
        Select $select,
        AbstractAttribute $attribute,
        string $alias,
        string $joinAlias
    ): string {
        if ($attribute->getBackendType() === 'static') {
            $expression = 'e.' . $attribute->getAttributeCode();
            $select->columns([$alias => $expression]);

            return $expression;
        }

        // `getBackendTable()` has already been through the resource's table resolution, so it is a
        // real table name — passing it through `getTableName()` again would prefix it twice.
        $select->joinLeft(
            [$joinAlias => $attribute->getBackendTable()],
            sprintf(
                '%1$s.entity_id = e.entity_id AND %1$s.attribute_id = %2$d AND %1$s.store_id = %3$d',
                $joinAlias,
                (int)$attribute->getAttributeId(),
                Store::DEFAULT_STORE_ID
            ),
            [$alias => $joinAlias . '.value']
        );

        return $joinAlias . '.value';
    }

    private function addStorefrontFilters(Select $select): void
    {
        $status = $this->requireAttribute(ProductInterface::STATUS);
        $visibility = $this->requireAttribute(ProductInterface::VISIBILITY);

        $select->joinInner(
            ['st' => $status->getBackendTable()],
            sprintf(
                'st.entity_id = e.entity_id AND st.attribute_id = %d AND st.store_id = %d',
                (int)$status->getAttributeId(),
                Store::DEFAULT_STORE_ID
            ),
            []
        )->joinInner(
            ['vis' => $visibility->getBackendTable()],
            sprintf(
                'vis.entity_id = e.entity_id AND vis.attribute_id = %d AND vis.store_id = %d',
                (int)$visibility->getAttributeId(),
                Store::DEFAULT_STORE_ID
            ),
            []
        )->where('st.value = ?', Status::STATUS_ENABLED)
            ->where('vis.value IN (?)', [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]);
    }

    /**
     * @throws LocalizedException
     */
    private function requireAttribute(string $attributeCode): AbstractAttribute
    {
        // Never null — an unknown code comes back as an empty attribute object, so the id is what
        // distinguishes it. See OptionSortOrder::getRanking() for the same reading.
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $attributeCode);
        if (!$attribute->getAttributeId()) {
            throw new LocalizedException(
                __('Product attribute "%1" does not exist.', $attributeCode)
            );
        }

        return $attribute;
    }
}
