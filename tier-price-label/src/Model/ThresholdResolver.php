<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\Model;

use Magento\Catalog\Pricing\Price\TierPrice;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\SaleableInterface;

/**
 * Answers the only question the "As low as" line leaves open: *how many* do I have to buy?
 *
 * The naive implementation takes the highest configured quantity, which is wrong the moment
 * a ladder is not monotonic — and non-monotonic ladders are common, because percentage tiers
 * are recalculated against a moving base price and merchandisers add rungs out of order. The
 * threshold this class returns is the quantity that unlocks the *cheapest* rung, i.e. the one
 * that matches the amount core is about to render.
 */
class ThresholdResolver
{
    /**
     * Money comparison tolerance. Tier values arrive as DECIMAL(20,6) strings cast to float,
     * so two "equal" rungs can differ in the last bit.
     */
    private const PRICE_EPSILON = 0.00001;

    /**
     * A rung reachable at a single unit is not a quantity story — it is just a lower price,
     * and "From 1 pcs" reads like a bug. Below this the module defers to core's wording.
     */
    private const MIN_THRESHOLD_QTY = 1.0;

    /**
     * @return float|null Quantity that unlocks the minimal price, or null when there is no
     *                    quantity-based story to tell and the caller should keep core's copy.
     */
    public function resolve(SaleableInterface $product): ?float
    {
        $tierPrice = $this->getTierPriceModel($product);
        if ($tierPrice === null) {
            return null;
        }

        $cheapestQty = null;
        $cheapestValue = null;

        foreach ($tierPrice->getTierPriceList() as $rung) {
            $amount = $rung['price'] ?? null;
            if (!$amount instanceof AmountInterface || !isset($rung['price_qty'])) {
                continue;
            }

            $qty = (float) $rung['price_qty'];
            $value = (float) $amount->getValue();

            // Cheapest wins; a tie falls back to the lowest quantity, so the label always
            // advertises the *first* quantity that reaches the advertised price.
            $isCheaper = $cheapestValue === null || $value < $cheapestValue - self::PRICE_EPSILON;
            $isEqualButEarlier = $cheapestValue !== null
                && abs($value - $cheapestValue) < self::PRICE_EPSILON
                && $qty < $cheapestQty;

            if ($isCheaper || $isEqualButEarlier) {
                $cheapestValue = $value;
                $cheapestQty = $qty;
            }
        }

        if ($cheapestQty === null || $cheapestQty <= self::MIN_THRESHOLD_QTY) {
            return null;
        }

        return $cheapestQty;
    }

    private function getTierPriceModel(SaleableInterface $product): ?TierPrice
    {
        try {
            $price = $product->getPriceInfo()->getPrice(TierPrice::PRICE_CODE);
        } catch (\InvalidArgumentException) {
            // A saleable item can ship a price pool without a tier price (custom price types,
            // gift cards from some vendors). Not an error — just nothing to relabel.
            return null;
        }

        return $price instanceof TierPrice ? $price : null;
    }
}
