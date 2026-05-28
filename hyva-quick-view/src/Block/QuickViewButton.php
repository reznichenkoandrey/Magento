<?php
declare(strict_types=1);

namespace Scr1be\HyvaQuickView\Block;

use Magento\Catalog\Block\Product\AbstractProduct;

/**
 * Empty AbstractProduct subclass — exists so Magento\Catalog\Block\Product\ProductList\Item\Container
 * passes the current product into this block via setProduct() when rendering the addto slot.
 * Plain Template blocks are skipped by the Container instanceof check.
 */
class QuickViewButton extends AbstractProduct
{
}
