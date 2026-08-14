<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model\Card;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Scr1be\HyvaProductCard\Model\Config;

/**
 * Decides which badges a product has earned. Four renderers ask; one answer.
 *
 * Two rules shape the whole class:
 *
 * 1. **A badge is never invented from missing data.** Every branch needs a positive fact — a
 *    `news_from_date` that exists, a price pool that answers, a quantity that was actually
 *    measured. Absent data produces no badge, never a default one.
 * 2. **The sale badge compares rendered prices, not attributes.** Reading `special_price` misses
 *    catalogue price rules, which is how most sales are actually run.
 */
class BadgeResolver
{
    /**
     * Sort order of the badge list. Stock urgency beats price, price beats novelty: a renderer with
     * room for exactly one badge should show the one that changes a decision.
     */
    private const PRIORITY_LOW_STOCK = 10;
    private const PRIORITY_SALE = 20;
    private const PRIORITY_NEW = 30;

    /**
     * Money arrives as DECIMAL(20,6) cast to float. Two prices that differ in the last bit are the
     * same price, and "Save 0%" is a rendering bug with a marketing cost.
     */
    private const PRICE_EPSILON = 0.00001;

    private const PERCENT = 100.0;

    public function __construct(
        private readonly Config $config,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * @return Badge[] Sorted by priority, ascending.
     */
    public function resolve(Product $product, ?StockPresentation $stock = null): array
    {
        $storeId = (int) $product->getStoreId();

        if (!$this->config->isEnabled($storeId)) {
            return [];
        }

        $badges = [];

        if ($this->config->isNewBadgeEnabled($storeId) && $this->isNew($product, $storeId)) {
            $badges[] = new Badge(Badge::CODE_NEW, (string) __('New'), self::PRIORITY_NEW);
        }

        if ($this->config->isSaleBadgeEnabled($storeId)) {
            $discount = $this->getDiscountPercent($product);
            if ($discount !== null && $discount >= $this->config->getSaleMinPercent($storeId)) {
                $badges[] = new Badge(
                    Badge::CODE_SALE,
                    (string) __('-%1%', (int) round($discount)),
                    self::PRIORITY_SALE,
                    $discount
                );
            }
        }

        if ($stock !== null && $stock->isLow()) {
            $badges[] = new Badge(
                Badge::CODE_LOW_STOCK,
                (string) __('Low stock'),
                self::PRIORITY_LOW_STOCK,
                $stock->getSalableQty()
            );
        }

        usort($badges, static fn (Badge $a, Badge $b): int => $a->getPriority() <=> $b->getPriority());

        return $badges;
    }

    /**
     * @return float|null Percentage saved, or null when there is no discount to speak of.
     */
    public function getDiscountPercent(Product $product): ?float
    {
        try {
            $priceInfo = $product->getPriceInfo();
            $regular = (float) $priceInfo->getPrice(RegularPrice::PRICE_CODE)->getAmount()->getValue();
            $final = (float) $priceInfo->getPrice(FinalPrice::PRICE_CODE)->getAmount()->getValue();
        } catch (\InvalidArgumentException) {
            // Price pools are per product type. A type that ships neither price (custom types from
            // some vendors) simply has no discount story — not an error worth a log line.
            return null;
        }

        if ($regular <= self::PRICE_EPSILON || $final >= $regular - self::PRICE_EPSILON) {
            return null;
        }

        return ($regular - $final) / $regular * self::PERCENT;
    }

    private function isNew(Product $product, int $storeId): bool
    {
        $from = (string) $product->getData('news_from_date');
        $to = (string) $product->getData('news_to_date');

        // `isScopeDateInInterval()` answers *true* when both bounds are empty — correct for its own
        // callers, wrong here, because every product in the catalogue has empty news dates. The
        // start date is what makes a product new; without it there is nothing to be inside of.
        if ($from === '') {
            return false;
        }

        return $this->timezone->isScopeDateInInterval($storeId, $from, $to !== '' ? $to : null);
    }
}
