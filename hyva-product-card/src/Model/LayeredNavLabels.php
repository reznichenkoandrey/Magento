<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model;

use Magento\Catalog\Model\Layer\FilterableAttributeListInterface;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;

/**
 * Attribute code → the label layered navigation would print for it.
 *
 * A card that shows "Color: Blue" and a facet list that shows "Colour" is the same defect as a
 * mistranslated string, except nobody files it because each half looks right on its own. The map
 * comes from the same service the layered navigation itself is built on —
 * `FilterableAttributeListInterface` — so a merchant who renames an attribute renames it
 * everywhere at once. Labels are the store-scoped ones: `Category\FilterableAttributeList::getList()`
 * calls `addStoreLabel()` for the current store before loading.
 *
 * Core ships no preference for that interface — it wires the two implementations by argument, one
 * per layer — so this module wires one explicitly in `etc/di.xml`. The category list
 * (`addIsFilterableFilter()`) is the default because card vocabulary is catalogue-wide; the search
 * list narrows to attributes filterable *in search* and would silently drop labels on a category
 * page. Swap the argument if your storefront wants the other one.
 */
class LayeredNavLabels
{
    /** @var array<string, string>|null */
    private ?array $memo = null;

    public function __construct(private readonly FilterableAttributeListInterface $filterableAttributes)
    {
    }

    /**
     * @return array<string, string> Attribute code => store-scoped frontend label.
     */
    public function getMap(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $map = [];
        foreach ($this->filterableAttributes->getList() as $attribute) {
            if (!$attribute instanceof AbstractAttribute) {
                continue;
            }

            $code = (string) $attribute->getAttributeCode();
            $label = (string) $attribute->getStoreLabel();

            if ($code !== '' && $label !== '') {
                $map[$code] = $label;
            }
        }

        return $this->memo = $map;
    }

    /**
     * @return string The attribute's own label, or the code itself — never an empty string, because
     *                an empty label renders as a stray colon on the card.
     */
    public function getLabel(string $attributeCode): string
    {
        return $this->getMap()[$attributeCode] ?? $attributeCode;
    }
}
