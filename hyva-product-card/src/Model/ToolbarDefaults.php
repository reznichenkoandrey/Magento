<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model;

use Magento\Catalog\Helper\Product\ProductList;

/**
 * Which sort the storefront considers "no sort chosen".
 *
 * The client grid re-sorts in the browser. If its idea of the default differs from the toolbar's by
 * so much as a tie-break, the first paint reorders itself the moment JavaScript runs — the most
 * expensive kind of layout shift, because it happens after the user has started reading.
 *
 * `Magento\Catalog\Helper\Product\ProductList::getDefaultSortField()` is the exact resolution the
 * toolbar block uses (`Toolbar::getOrderField()` delegates to it): the current category's
 * `default_sort_by` when a category is registered, otherwise `catalog/frontend/default_sort_by`.
 * Direction has no per-category override in core — `ProductList::DEFAULT_SORT_DIRECTION` is the
 * value `Toolbar::$_direction` is initialised to, and `getCurrentDirection()` falls back to it
 * whenever the memorised direction is absent or not one of asc/desc.
 */
class ToolbarDefaults
{
    public function __construct(private readonly ProductList $productListHelper)
    {
    }

    public function getSortField(): string
    {
        return (string) $this->productListHelper->getDefaultSortField();
    }

    public function getDirection(): string
    {
        return ProductList::DEFAULT_SORT_DIRECTION;
    }

    /**
     * @return array{sort: string, direction: string}
     */
    public function toArray(): array
    {
        return [
            'sort' => $this->getSortField(),
            'direction' => $this->getDirection(),
        ];
    }
}
