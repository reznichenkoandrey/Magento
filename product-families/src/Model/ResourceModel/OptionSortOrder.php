<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model\ResourceModel;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;

/**
 * The ranking behind every family row: `eav_attribute_option.sort_order`, read once per reconcile.
 *
 * The rank returned is the row's index in `ORDER BY sort_order, option_id` rather than the raw
 * `sort_order` value. Those are the same ordering, but the index is dense and the raw value is not —
 * an attribute whose options were reordered by hand in the admin routinely ends up with two options
 * sharing a sort order, and a dense index makes the tie resolve on option id here instead of leaving
 * it to the sort in `PositionResolver` to discover.
 */
class OptionSortOrder
{
    private const OPTION_TABLE = 'eav_attribute_option';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @return array<string, int> option id (as it is stored in the value table) => rank from 1
     */
    public function getRanking(string $attributeCode): array
    {
        if ($attributeCode === '') {
            return [];
        }

        // `Config::getAttribute()` never answers null: for a code it does not know it builds an
        // empty attribute object and returns that. So the id is the only thing worth testing —
        // an unknown code is an attribute with no id, not an absent one.
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $attributeCode);
        if (!$attribute->getAttributeId()) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::OPTION_TABLE), ['option_id'])
            ->where('attribute_id = ?', (int)$attribute->getAttributeId())
            ->order(['sort_order ASC', 'option_id ASC']);

        $ranking = [];
        $rank = 0;
        foreach ($connection->fetchCol($select) as $optionId) {
            // Keyed by string because the value tables hand option ids back as strings, and the
            // caller looks the rank up with whatever the scan produced.
            $ranking[(string)$optionId] = ++$rank;
        }

        return $ranking;
    }
}
