<?php
declare(strict_types=1);

namespace Scr1be\HyvaQuickView\ViewModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Product as ProductHelper;
use Magento\Checkout\Helper\Cart as CartHelper;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

class QuickView implements ArgumentInterface
{
    public const QUICKVIEW_INFO_PATH = 'hyva-quickview/product/info';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly PriceHelper $priceHelper,
        private readonly CartHelper $cartHelper,
        private readonly UrlInterface $urlBuilder,
        private readonly ProductHelper $productHelper,
        private readonly StoreManagerInterface $storeManager,
        private readonly FormKey $formKey,
    ) {
    }

    /**
     * A quick view is a storefront read, so it owes the shopper exactly what a product page owes
     * them — no more. The repository alone does not deliver that: getById() resolves a row and
     * throws only when the id does not exist, with no status, visibility or website check. Used
     * bare it turns this endpoint into an id enumerator that answers for disabled products,
     * "Not Visible Individually" children and products belonging to another website.
     *
     * The two checks below are the ones Magento\Catalog\Helper\Product::initProduct() performs
     * before a product page renders anything, in the same order, so this endpoint is visible
     * exactly where the catalog is.
     *
     * @throws NoSuchEntityException
     */
    public function getProduct(int $productId): ProductInterface
    {
        $store = $this->storeManager->getStore();
        $product = $this->productRepository->getById($productId, false, (int) $store->getId());

        if (!$this->productHelper->canShow($product)
            || !in_array($store->getWebsiteId(), $product->getWebsiteIds())
        ) {
            // Same exception the missing-row path throws: a hidden product and an absent one are
            // indistinguishable from outside, which is the point.
            throw NoSuchEntityException::singleField('id', $productId);
        }

        return $product;
    }

    /** Rendered into the AJAX body, which is per-session and never cached. */
    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function formatPrice(ProductInterface $product): string
    {
        return $this->priceHelper->currency($product->getFinalPrice(), true, false);
    }

    /**
     * useUencPlaceholder is not optional here. Without it getAddUrl() encodes
     * $urlBuilder->getCurrentUrl(), and "current" inside this module is the AJAX endpoint that
     * rendered the modal — so the post-add redirect would send the shopper to a JSON document.
     * The placeholder defers the decision to submit time, where Hyvä's global submit listener
     * (Magento_Theme::page/js/set-uenc.phtml) swaps in the real page URL.
     */
    public function getAddToCartUrl(ProductInterface $product): string
    {
        return $this->cartHelper->getAddUrl($product, ['useUencPlaceholder' => true]);
    }

    public function getInfoEndpoint(): string
    {
        return $this->urlBuilder->getUrl(self::QUICKVIEW_INFO_PATH);
    }
}
