<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model\Config\Source;

/**
 * Attributes offered as the variant — the one that orders a family row and labels its chips.
 *
 * Only `select`, because the ordering comes from `eav_attribute_option.sort_order` and a free-text
 * or multiselect attribute has nothing to read there. A multiselect would additionally give a chip
 * two labels at once, which a swatch cannot render.
 *
 * The empty option is meaningful rather than a placeholder: a family with no variant attribute is
 * ordered by product id and labelled by product name, which is what "similar products" is.
 */
class VariantAttributes extends GroupAttributes
{
    protected const ALLOWED_INPUTS = ['select'];

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function getLeadingOptions(): array
    {
        return [['value' => '', 'label' => (string)__('-- None (order by product id) --')]];
    }
}
