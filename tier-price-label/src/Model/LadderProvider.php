<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\Model;

use Magento\Catalog\Api\Data\ProductTierPriceInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\Customer\Model\Group;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Reads the tier ladder as an admin configured it, not as core wants to print it.
 *
 * Magento\Catalog\Pricing\Price\TierPrice::getTierPriceList() is a *rendering* list: it drops
 * every rung that is not cheaper than the product's current final price and collapses the
 * ladder to what the stock discount table wants to show. That is the right answer for
 * "print the table" and the wrong answer for a client-side qty -> price calculator, which has
 * to know about rungs core currently hides — otherwise a special price that temporarily
 * undercuts rung 1 makes the widget show the unit price jumping *up* as the shopper types a
 * larger quantity.
 *
 * So this provider goes back to the stored ladder and applies only the filters that decide
 * *eligibility* (customer group, website) rather than presentation. Deciding whether a rung is
 * actually a discount is left to the consumer, which compares against the live final price.
 */
class LadderProvider
{
    /**
     * Website id 0 means "all websites" in catalog_product_entity_tier_price.
     */
    private const ALL_WEBSITES_ID = 0;

    private const PERCENT_BASE = 100.0;

    public function __construct(
        private readonly CustomerGroupResolver $customerGroupResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    /**
     * @return TierRung[] ordered by ascending quantity
     */
    public function getLadder(Product $product): array
    {
        $groupId = $this->customerGroupResolver->getCurrentGroupId();
        $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
        $regularPrice = (float) $product->getPriceInfo()->getPrice(RegularPrice::PRICE_CODE)->getValue();

        /** @var array<string, TierRung> $rungs keyed by quantity */
        $rungs = [];

        foreach ((array) $product->getTierPrices() as $tierPrice) {
            if (!$tierPrice instanceof ProductTierPriceInterface) {
                continue;
            }
            if (!$this->appliesToGroup($tierPrice, $groupId) || !$this->appliesToWebsite($tierPrice, $websiteId)) {
                continue;
            }

            $percentage = $this->resolvePercentage($tierPrice);
            $value = $this->resolveValue($tierPrice, $percentage, $regularPrice);
            if ($value === null) {
                continue;
            }

            $qty = (float) $tierPrice->getQty();
            $key = (string) $qty;

            // The same quantity can legally carry one row per group and per website; once
            // both are eligible the shopper gets the cheaper of them, so quote that one.
            if (isset($rungs[$key]) && $rungs[$key]->getValue() <= $value) {
                continue;
            }

            $rungs[$key] = new TierRung(
                $qty,
                $value,
                $this->priceCurrency->format($value, false),
                $percentage
            );
        }

        $ladder = array_values($rungs);
        usort($ladder, static fn (TierRung $a, TierRung $b): int => $a->getQty() <=> $b->getQty());

        return $ladder;
    }

    private function appliesToGroup(ProductTierPriceInterface $tierPrice, int $groupId): bool
    {
        $tierGroupId = (int) $tierPrice->getCustomerGroupId();

        return $tierGroupId === $groupId || $tierGroupId === Group::CUST_GROUP_ALL;
    }

    private function appliesToWebsite(ProductTierPriceInterface $tierPrice, int $websiteId): bool
    {
        $extensionAttributes = $tierPrice->getExtensionAttributes();
        $tierWebsiteId = $extensionAttributes === null
            ? self::ALL_WEBSITES_ID
            : (int) $extensionAttributes->getWebsiteId();

        return $tierWebsiteId === self::ALL_WEBSITES_ID || $tierWebsiteId === $websiteId;
    }

    private function resolvePercentage(ProductTierPriceInterface $tierPrice): ?float
    {
        $extensionAttributes = $tierPrice->getExtensionAttributes();
        if ($extensionAttributes === null || $extensionAttributes->getPercentageValue() === null) {
            return null;
        }

        return (float) $extensionAttributes->getPercentageValue();
    }

    /**
     * Percentage rungs normally arrive with the money value already resolved by the tier-price
     * backend; the percentage arm below only fires for products whose tier prices were set
     * through the API without a value, which the REST contract allows.
     */
    private function resolveValue(
        ProductTierPriceInterface $tierPrice,
        ?float $percentage,
        float $regularPrice
    ): ?float {
        $value = $tierPrice->getValue();
        if ($value !== null && (float) $value > 0.0) {
            return (float) $value;
        }

        if ($percentage !== null && $regularPrice > 0.0) {
            return $regularPrice * (self::PERCENT_BASE - $percentage) / self::PERCENT_BASE;
        }

        return null;
    }
}
