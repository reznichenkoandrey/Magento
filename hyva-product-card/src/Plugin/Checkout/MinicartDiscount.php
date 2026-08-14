<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Plugin\Checkout;

use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\Checkout\CustomerData\ItemPoolInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote\Item;
use Scr1be\HyvaProductCard\Model\Config;

/**
 * Gives the minicart the same discount vocabulary the card has.
 *
 * A shopper who added a product from a card showing "-30%" and a struck-through price opens the
 * minicart and finds one number. Nothing is *wrong* — core's `DefaultItem::doGetItemData()` returns
 * `product_price` and `product_price_value` and stops there — but the drawer silently drops the
 * only reason they clicked.
 *
 * `ItemPoolInterface::getItemData()` is the choke point worth plugging: `Magento\Checkout\
 * CustomerData\ItemPool` is what the `cart` customer-data section runs every quote item through,
 * and the interface (not the class) is what `etc/frontend/di.xml` maps a preference for — so one
 * `after` plugin on the interface reaches every item renderer, including the per-product-type ones
 * a third-party module registers in the pool.
 *
 * `after` because the plugin adds keys to a finished array; there is no decision to intercept and
 * nothing to prevent. An `around` here would only add a way to break the pool by forgetting to
 * call it.
 */
class MinicartDiscount
{
    /**
     * Quote item prices are DECIMAL(20,6) cast to float, and a percentage-based catalogue rule can
     * land the calculation price a fraction of a cent under the regular price. Without a tolerance
     * the drawer decorates a "discount" of 0.0000001 with a struck-through price identical to the
     * one next to it.
     */
    private const PRICE_EPSILON = 0.00001;

    public function __construct(
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterGetItemData(ItemPoolInterface $subject, array $result, Item $item): array
    {
        if (!$this->config->isEnabled()) {
            return $result;
        }

        $regular = $this->getRegularPrice($item);
        if ($regular === null) {
            return $result;
        }

        // The same basis core used for `product_price_value`, so the two numbers in the drawer are
        // comparable rather than merely adjacent.
        $final = (float) $item->getCalculationPrice();

        $hasDiscount = $regular - $final > self::PRICE_EPSILON;

        $result['has_discount'] = $hasDiscount;
        $result['regular_price_value'] = $regular;
        $result['regular_price'] = $hasDiscount ? $this->priceCurrency->format($regular, false) : null;

        return $result;
    }

    private function getRegularPrice(Item $item): ?float
    {
        $product = $item->getProduct();
        if ($product === null) {
            return null;
        }

        try {
            $regular = (float) $product->getPriceInfo()
                ->getPrice(RegularPrice::PRICE_CODE)
                ->getAmount()
                ->getValue();
        } catch (\InvalidArgumentException) {
            // Product types ship their own price pools; one without a regular price has no
            // before/after story to tell, and the drawer keeps core's single number.
            return null;
        }

        return $regular > 0 ? $regular : null;
    }
}
