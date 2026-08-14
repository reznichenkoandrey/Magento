<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ProductSource;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;

/**
 * Everything built on one attribute set, newest first.
 *
 * Useful where a set doubles as a product family — "Gift Card", "Bundle Box", "Sale Sample" — and the
 * merchant would otherwise have to maintain a category that duplicates it. `attribute_set_id` is a
 * column on `catalog_product_entity`, so this is the cheapest source in the module: one indexed read,
 * no joins, no EAV.
 */
class AttributeSetProducts extends AbstractSource
{
    public const CODE = 'attribute_set';

    private const TABLE = 'catalog_product_entity';

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string) __('Attribute Set');
    }

    public function validateSourceValue(?string $sourceValue): void
    {
        if ((int) $sourceValue <= 0) {
            throw new LocalizedException(__('Choose an attribute set for the Attribute Set source.'));
        }
    }

    /**
     * @return int[]
     */
    public function getProductIds(SliderInterface $slider, int $storeId, int $limit): array
    {
        $attributeSetId = (int) $slider->getSourceValue();
        if ($attributeSetId <= 0) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['e' => $this->resourceConnection->getTableName(self::TABLE)], ['entity_id'])
            ->where('e.attribute_set_id = ?', $attributeSetId)
            ->order('e.entity_id DESC')
            ->limit($limit);

        return array_map('intval', $connection->fetchCol($select));
    }
}
