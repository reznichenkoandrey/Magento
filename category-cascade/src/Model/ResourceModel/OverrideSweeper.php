<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Model\ResourceModel;

use Magento\Catalog\Api\Data\CategoryAttributeInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\Store;

/**
 * One UPDATE that closes the hole a per-scope cascade would otherwise leave.
 *
 * `is_active` is store-scoped. Disabling a child in the default scope writes the store_id = 0 row,
 * and any store view that carries its own `is_active = 1` override keeps that child enabled — the
 * cascade would look like it worked in the admin and be visibly wrong on one storefront. Every one
 * of those overrides has to go, and there is no reason to visit them one row at a time: they are
 * identified by (attribute, store scope, value, id set), which is one WHERE clause.
 *
 * Values are set to 0 rather than deleted. A deleted row silently re-inherits whatever the default
 * scope says later; a row set to 0 is a per-store decision the merchant can still see and flip
 * back, which is the same shape the admin would have produced by hand.
 */
class OverrideSweeper
{
    private const TABLE_CATEGORY_INT = 'catalog_category_entity_int';
    private const ATTRIBUTE_IS_ACTIVE = 'is_active';
    private const VALUE_ENABLED = 1;
    private const VALUE_DISABLED = 0;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * @param int[] $categoryIds
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return int number of override rows changed
     */
    public function clearEnabledOverrides(array $categoryIds): int
    {
        if ($categoryIds === []) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();
        $attributeId = (int) $this->eavConfig
            ->getAttribute(CategoryAttributeInterface::ENTITY_TYPE_CODE, self::ATTRIBUTE_IS_ACTIVE)
            ->getAttributeId();

        return (int) $connection->update(
            $this->resourceConnection->getTableName(self::TABLE_CATEGORY_INT),
            ['value' => self::VALUE_DISABLED],
            [
                'attribute_id = ?' => $attributeId,
                'store_id > ?' => Store::DEFAULT_STORE_ID,
                'value = ?' => self::VALUE_ENABLED,
                $linkField . ' IN (?)' => $categoryIds,
            ]
        );
    }
}
