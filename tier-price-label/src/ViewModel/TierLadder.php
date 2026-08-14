<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Scr1be\TierPriceLabel\Model\LadderProvider;
use Scr1be\TierPriceLabel\Model\QtyFormatter;
use Scr1be\TierPriceLabel\Model\ThresholdResolver;
use Scr1be\TierPriceLabel\Model\TierRung;

/**
 * The module's public surface for Hyvä templates.
 *
 * It exposes the raw ladder (see LadderProvider for why "raw") plus everything a client-side
 * qty -> price calculator needs to do its own maths: the live final price to compare rungs
 * against, and the currency/locale pair so Intl.NumberFormat produces the same string the
 * server would have.
 *
 * Nothing here reads the session, so every value is safe to render into a full-page-cached
 * block: the customer group comes from the HTTP context the FPC already varies on.
 */
class TierLadder implements ArgumentInterface
{
    /**
     * @var array<int, TierRung[]> ladder per product id — the PDP asks for it three times.
     */
    private array $ladderCache = [];

    public function __construct(
        private readonly LadderProvider $ladderProvider,
        private readonly ThresholdResolver $thresholdResolver,
        private readonly QtyFormatter $qtyFormatter,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly ResolverInterface $localeResolver,
        private readonly Json $serializer
    ) {
    }

    /**
     * @return TierRung[] ordered by ascending quantity
     */
    public function getLadder(Product $product): array
    {
        $productId = (int) $product->getId();

        if (!isset($this->ladderCache[$productId])) {
            $this->ladderCache[$productId] = $this->ladderProvider->getLadder($product);
        }

        return $this->ladderCache[$productId];
    }

    public function hasLadder(Product $product): bool
    {
        return $this->getLadder($product) !== [];
    }

    /**
     * Quantity that unlocks the cheapest rung — the number the storefront label advertises.
     */
    public function getThresholdQty(Product $product): ?float
    {
        return $this->thresholdResolver->resolve($product);
    }

    public function formatQty(float $qty): string
    {
        return $this->qtyFormatter->format($qty);
    }

    /**
     * JSON payload for the Alpine calculator.
     *
     * `basePrice` is the *final* price, not the regular one: it is what the shopper pays today,
     * so it is also the number a rung has to beat before the widget may call it a discount.
     */
    public function getCalculatorPayload(Product $product): string
    {
        $rungs = array_map(
            static fn (TierRung $rung): array => $rung->toArray(),
            $this->getLadder($product)
        );

        return $this->serializer->serialize([
            'basePrice' => (float) $product->getPriceInfo()->getPrice(FinalPrice::PRICE_CODE)->getValue(),
            'currency' => $this->priceCurrency->getCurrency()->getCurrencyCode(),
            // PHP locales are underscore-separated, BCP 47 (what Intl.NumberFormat expects) is not.
            'locale' => str_replace('_', '-', $this->localeResolver->getLocale()),
            'rungs' => $rungs,
        ]);
    }
}
