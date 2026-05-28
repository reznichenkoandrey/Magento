<?php
declare(strict_types=1);

namespace Scr1be\HyvaQuickView\Block;

use Magento\Catalog\Block\Product\AbstractProduct;

/**
 * AbstractProduct subclass that pulls the product from its parent (the catalog.list.item.addto
 * Container) at render time.
 *
 * Why this is needed: Hyvä's product/list/item.phtml renders the addto slot via
 * `$addToBlock->setProduct($product)->getChildHtml()`. `getChildHtml()` walks children through
 * `Layout::renderElement()`, which calls each child's `_toHtml()` directly and bypasses
 * `Container::_toHtml()` — the place where Magento normally propagates the product to AbstractProduct
 * children. The result: setProduct fires on the Container, but never reaches us. We compensate here.
 */
class QuickViewButton extends AbstractProduct
{
    protected function _beforeToHtml()
    {
        if (!$this->getProduct() && ($parent = $this->getParentBlock()) && $parent->getProduct()) {
            $this->setProduct($parent->getProduct());
        }
        return parent::_beforeToHtml();
    }
}
