<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model;

use Magento\Catalog\Helper\Data as CatalogData;
use Magento\Catalog\Helper\ImageFactory;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * The one place an `AlertItem` becomes something a template or a JSON response can hold.
 *
 * Both storefront surfaces go through it — the customer-data section that feeds the popup and the
 * account page block — because the two would otherwise drift into showing the same product at two
 * different prices, and the first anyone would hear of it is a support ticket.
 *
 * The GraphQL resolver deliberately does *not* use this class. Everything in here is a decision
 * about presentation in the current storefront scope: an image resized against the active theme's
 * `view.xml`, a price run through the customer session's tax address, a currency formatted for the
 * store. None of that is a safe thing to bake into an API response.
 */
class AlertItemFormatter
{
    /**
     * Hyvä's `etc/view.xml` declares `product_small_image` at 135×135, which is the card size.
     */
    private const IMAGE_ID = 'product_small_image';

    public function __construct(
        private readonly ImageFactory $imageFactory,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly CatalogData $catalogData
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(AlertItem $item, ?int $storeId = null): array
    {
        $product = $item->product;
        $image = $this->imageFactory->create()->init($product, self::IMAGE_ID);

        return [
            'alert_id' => $item->alertId,
            'product_id' => (int)$product->getId(),
            'sku' => (string)$product->getSku(),
            'name' => (string)$product->getName(),
            'url' => (string)$product->getProductUrl(),
            'image' => [
                'src' => $image->getUrl(),
                'width' => (int)$image->getWidth(),
                'height' => (int)$image->getHeight(),
                'alt' => (string)$image->getLabel(),
            ],
            'price' => $this->formatPrice($item, $item->finalPrice, $storeId),
            // Null rather than the same string twice: the template shows a struck-through price only
            // when there is a second price to strike through.
            'regular_price' => $item->isDiscounted()
                ? $this->formatPrice($item, $item->regularPrice, $storeId)
                : null,
            'rating_summary' => $item->ratingSummary,
            'reviews_count' => $item->reviewsCount,
            'badges' => $item->badges,
            'can_add_to_cart' => $item->isAddToCartable(),
            'is_salable' => $item->isSalable,
            'qty' => $item->qtyRules->toArray(),
            'subscribed_at' => $item->subscribedAt,
            'restocked_at' => $item->restockedAt,
            'alert_status' => $item->alertStatus,
            'popup_status' => $item->popupStatus,
        ];
    }

    /**
     * Catalog price, taxed the way the storefront taxes it, then formatted in the store's currency.
     *
     * `Magento\Catalog\Helper\Data::getTaxPrice()` is the same call
     * `Magento\ProductAlert\Model\Mailing\AlertProcessor::savePriceAlert()` makes before handing a
     * price to the alert email, so a card and the email that preceded it agree on the number.
     */
    private function formatPrice(AlertItem $item, float $amount, ?int $storeId): string
    {
        $taxed = (float)$this->catalogData->getTaxPrice($item->product, $amount);

        return (string)$this->priceCurrency->format($taxed, false, PriceCurrencyInterface::DEFAULT_PRECISION, $storeId);
    }
}
