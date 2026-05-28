<?php
declare(strict_types=1);

namespace Scr1be\HyvaQuickView\ViewModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class QuickView implements ArgumentInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PriceHelper $priceHelper,
    ) {
    }

    public function getProduct(int $productId): ProductInterface
    {
        return $this->productRepository->getById($productId);
    }

    public function formatPrice(ProductInterface $product): string
    {
        return $this->priceHelper->currency($product->getFinalPrice(), true, false);
    }

    public function getAddToCartUrl(ProductInterface $product): string
    {
        return '/checkout/cart/add?product=' . $product->getId();
    }
}
