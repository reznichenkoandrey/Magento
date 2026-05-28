<?php
declare(strict_types=1);

namespace Scr1be\HyvaQuickView\ViewModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Helper\Cart as CartHelper;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class QuickView implements ArgumentInterface
{
    public const QUICKVIEW_INFO_PATH = 'hyva-quickview/product/info';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PriceHelper $priceHelper,
        private readonly CartHelper $cartHelper,
        private readonly UrlInterface $urlBuilder,
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
        // Magento\Checkout\Helper\Cart::getAddUrl includes form_key, store-scoped base URL,
        // and the correct request path (`checkout/cart/add`) — none of which we should hardcode.
        return $this->cartHelper->getAddUrl($product);
    }

    public function getInfoEndpoint(): string
    {
        return $this->urlBuilder->getUrl(self::QUICKVIEW_INFO_PATH);
    }
}
