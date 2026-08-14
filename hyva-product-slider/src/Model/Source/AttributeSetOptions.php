<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\Source;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Attribute sets of the product entity, for the Attribute Set source's picker.
 *
 * The entity type is resolved through `Eav\Model\Config` rather than hardcoded as `4`. That integer
 * is stable on a stock install and is not a contract — it is an autoincrement value, and it differs
 * on any database where the EAV entity types were created in another order.
 */
class AttributeSetOptions implements OptionSourceInterface
{
    public function __construct(
        private readonly CollectionFactory $attributeSetCollectionFactory,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @return array<int, array{value: int|string, label: string}>
     */
    public function toOptionArray(): array
    {
        try {
            $entityTypeId = (int) $this->eavConfig->getEntityType(Product::ENTITY)->getId();
        } catch (LocalizedException) {
            return [];
        }

        $collection = $this->attributeSetCollectionFactory->create();
        $collection->setEntityTypeFilter($entityTypeId)
            ->setOrder('attribute_set_name', 'ASC');

        $options = [['value' => '', 'label' => (string) __('-- Please Select --')]];

        foreach ($collection as $attributeSet) {
            $options[] = [
                'value' => (int) $attributeSet->getId(),
                'label' => (string) $attributeSet->getAttributeSetName(),
            ];
        }

        return $options;
    }
}
