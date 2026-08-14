<?php
declare(strict_types=1);

namespace Scr1be\HyvaCompareDrawer\Block;

use Magento\Catalog\Block\Product\ProductList\Item\Block as ProductListItemBlock;

/**
 * The compare button rendered into Hyvä's `catalog.list.item.addto` slot.
 *
 * The base class is the whole trick. That slot's container is
 * Magento\Catalog\Block\Product\ProductList\Item\Container, whose getChildHtml() override pushes
 * the current product into each child that implements Magento\Catalog\Block\Product\AwareInterface
 * — and only into those. A plain AbstractProduct subclass does not implement it, so it renders
 * with no product and needs a getParentBlock() workaround to compensate.
 *
 * Item\Block is core's own base for this slot and already implements the interface, so the
 * product arrives by the same mechanism core uses for its own buttons. It also settles the
 * card-reuse problem for free: the container assigns before every child render, so one block
 * instance serving every card on the page cannot carry a stale product from the previous one.
 */
class CompareButton extends ProductListItemBlock
{
}
