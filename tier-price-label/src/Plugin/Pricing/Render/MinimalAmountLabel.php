<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\Plugin\Pricing\Render;

use Magento\Catalog\Pricing\Price\MinimalPriceCalculatorInterface;
use Magento\Catalog\Pricing\Render\FinalPriceBox;
use Scr1be\TierPriceLabel\Model\QtyFormatter;
use Scr1be\TierPriceLabel\Model\ThresholdResolver;

/**
 * Turns core's "As low as $9.00" into "From 5 pcs — $9.00".
 *
 * Why `around` and not the cheaper hooks:
 *
 * - `before` is useless: FinalPriceBox::renderAmountMinimal() takes no arguments and builds
 *   the label internally, so there is nothing to intercept on the way in.
 * - `after` only sees the finished HTML string. Rewriting the label there means a regex over
 *   rendered markup — it breaks on the first translated storefront and on any theme that
 *   changes the price-label element.
 * - `around` lets us do the one thing that is actually safe: re-issue the *same* public
 *   render call core would have made, with a single argument replaced.
 *
 * The cost of `around` is that plugins registered after this one never see `$proceed` when we
 * take over. That is acceptable here because we do not skip core's rendering — we delegate to
 * the same public FinalPriceBox::renderAmount() that core delegates to, so amount renderers,
 * their own plugins and the price-box template chain all still run.
 *
 * Every uncertainty resolves to `$proceed()`: no minimal amount, no quantity worth naming, an
 * exotic price pool — the shopper gets stock Magento wording instead of a broken line.
 */
class MinimalAmountLabel
{
    /**
     * Copy lives here rather than in a system.xml field: the only variable in this module is
     * wording, and wording belongs in translation files where a merchant's translator can
     * reach it without an admin round trip. Override via i18n/<locale>.csv.
     */
    private const LABEL_PATTERN = 'From %1 pcs —';

    /**
     * The id FinalPriceBox::renderAmountMinimal() falls back to when the block carries no
     * explicit price id — vendor/magento/module-catalog/Pricing/Render/FinalPriceBox.php:130.
     * Mirrored as a literal because core spells it inline; there is no constant to import.
     */
    private const FALLBACK_PRICE_ID_PREFIX = 'product-minimal-price-';

    public function __construct(
        private readonly MinimalPriceCalculatorInterface $minimalPriceCalculator,
        private readonly ThresholdResolver $thresholdResolver,
        private readonly QtyFormatter $qtyFormatter
    ) {
    }

    /**
     * @param FinalPriceBox $subject
     * @param callable $proceed
     * @return string
     */
    public function aroundRenderAmountMinimal(FinalPriceBox $subject, callable $proceed): string
    {
        $product = $subject->getSaleableItem();

        // Resolved through the same calculator core uses, so the amount we render is the
        // amount core would have rendered — only the label differs.
        $amount = $this->minimalPriceCalculator->getAmount($product);
        if ($amount === null) {
            return (string) $proceed();
        }

        $thresholdQty = $this->thresholdResolver->resolve($product);
        if ($thresholdQty === null) {
            return (string) $proceed();
        }

        // Same argument set core passes, with display_label as the single substitution.
        return (string) $subject->renderAmount(
            $amount,
            [
                'display_label' => __(self::LABEL_PATTERN, $this->qtyFormatter->format($thresholdQty)),
                'price_id' => $this->buildPriceId($subject),
                'include_container' => false,
                'skip_adjustments' => false,
            ]
        );
    }

    /**
     * Core resolves the id via PriceBox::getPriceId() — which composes price_id, or
     * price_id_prefix + product id + price_id_suffix from block data
     * (vendor/magento/framework/Pricing/Render/PriceBox.php:111) — and falls back to
     * "product-minimal-price-<id>" when that yields nothing (FinalPriceBox.php:130).
     *
     * Delegating rather than recomputing matters on Hyvä: the theme's amount template gates
     * both `x-id` and `:id="$id(...)"` on a truthy price id
     * (magento2-default-theme/Magento_Catalog/templates/product/price/amount/default.phtml:15,22),
     * so an empty id would leave the price node outside Alpine's id scope and configurable
     * swatch selection would stop updating it.
     */
    private function buildPriceId(FinalPriceBox $subject): string
    {
        $priceId = (string) $subject->getPriceId();

        return $priceId !== ''
            ? $priceId
            : self::FALLBACK_PRICE_ID_PREFIX . $subject->getSaleableItem()->getId();
    }
}
