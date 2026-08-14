<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Attributes offered as a family key.
 *
 * The filter is on `frontend_input` rather than on backend type, because what makes an attribute a
 * usable family key is that its values repeat. A select, a multiselect, a yes/no or a short text can
 * do that; a textarea, a date, a price or an image cannot, and offering them would produce families
 * of one on every product in the catalogue.
 *
 * The list is deliberately not restricted to global-scope attributes even though the reconcile reads
 * the default scope only — a store-scoped attribute still has a default value, and refusing to offer
 * it would rule out attributes that are perfectly good keys and happen to be translatable.
 */
class GroupAttributes implements OptionSourceInterface
{
    protected const ALLOWED_INPUTS = ['select', 'multiselect', 'boolean', 'text'];

    public function __construct(
        private readonly CollectionFactory $attributeCollectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $collection = $this->attributeCollectionFactory->create();
        $collection->addFieldToFilter('frontend_input', ['in' => static::ALLOWED_INPUTS])
            ->setOrder('attribute_code', 'ASC');

        $options = $this->getLeadingOptions();
        foreach ($collection as $attribute) {
            $code = (string)$attribute->getAttributeCode();
            $label = trim((string)$attribute->getDefaultFrontendLabel());

            $options[] = [
                'value' => $code,
                // The code is in the label on purpose: two attributes routinely share a frontend
                // label ("Size" on two attribute sets), and the code is what the CLI prints.
                'label' => $label !== '' ? sprintf('%s (%s)', $label, $code) : $code,
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function getLeadingOptions(): array
    {
        return [['value' => '', 'label' => (string)__('-- Not configured --')]];
    }
}
