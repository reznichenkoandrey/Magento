<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * The storefront read: one product's links for one link type, in family order.
 *
 * Deliberately not a link collection. `Magento\Catalog\Model\ResourceModel\Product\Link\Collection`
 * would give the same ids, but it instantiates a `Magento\Catalog\Model\Product\Link` model per row
 * on the way — and the caller wants product ids, which it then hands to one product collection for
 * all three families at once. Two queries per product page rather than four collections.
 *
 * The order is `position, link_id`. The tie-break matters: two links that share a position — which
 * only happens when a multiselect family key put the same pair in two families — would otherwise
 * render in whatever order InnoDB returned them, and the row would appear to shuffle between page
 * loads.
 */
class FamilyLinkReader
{
    private const LINK_TABLE = 'catalog_product_link';
    private const LINK_ATTRIBUTE_INT_TABLE = 'catalog_product_link_attribute_int';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LinkPositionAttribute $positionAttribute
    ) {
    }

    /**
     * @return int[] linked product ids, ordered
     */
    public function getLinkedProductIds(int $productId, int $linkTypeId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['l' => $this->resource->getTableName(self::LINK_TABLE)], ['linked_product_id'])
            ->joinLeft(
                ['p' => $this->resource->getTableName(self::LINK_ATTRIBUTE_INT_TABLE)],
                sprintf(
                    'p.link_id = l.link_id AND p.product_link_attribute_id = %d',
                    $this->positionAttribute->getId($linkTypeId)
                ),
                []
            )
            ->where('l.product_id = ?', $productId)
            ->where('l.link_type_id = ?', $linkTypeId)
            ->order(['p.value ASC', 'l.link_id ASC']);

        return array_map('intval', $connection->fetchCol($select));
    }
}
