<?php
declare(strict_types=1);

namespace Scr1be\HyvaCompareDrawer\Block;

use Magento\Catalog\Block\Product\AbstractProduct;

/**
 * Empty AbstractProduct subclass — needed so Magento\Catalog\Block\Product\ProductList\Item\Container
 * passes the current product via setProduct() into this block when rendering the addto slot.
 */
class CompareButton extends AbstractProduct
{
}
