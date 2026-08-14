<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model\Card;

use Scr1be\HyvaProductCard\Model\Config;

/**
 * The one place that turns "is this product available, and how nearly gone is it" into words.
 *
 * There are three callers with three different amounts of knowledge — the server card knows only a
 * boolean from the stock status index, the stock endpoint knows a quantity, the minicart knows
 * neither — and they must not each invent their own wording. Everything else in the module treats
 * this class as the vocabulary.
 */
class StockPresenter
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param bool $inStock Availability as the caller already knows it.
     * @param float|null $salableQty Remaining quantity when the caller has one, null when not.
     */
    public function present(bool $inStock, ?float $salableQty = null, ?int $storeId = null): StockPresentation
    {
        if (!$inStock) {
            return new StockPresentation(false, (string) __('Out of stock'), false, $salableQty);
        }

        $isLow = $this->isLow($salableQty, $storeId);

        $label = $isLow
            // The number is deliberately in the label rather than only in the badge: "Only 3 left"
            // is the whole message, and a screen reader should not have to assemble it from two
            // separate nodes.
            ? (string) __('Only %1 left', $this->formatQty((float) $salableQty))
            : (string) __('In stock');

        return new StockPresentation(true, $label, $isLow, $salableQty);
    }

    public function isLow(?float $salableQty, ?int $storeId = null): bool
    {
        if ($salableQty === null || !$this->config->isLowStockBadgeEnabled($storeId)) {
            return false;
        }

        $threshold = $this->config->getLowStockThreshold($storeId);

        return $threshold > 0 && $salableQty > 0 && $salableQty <= $threshold;
    }

    /**
     * Quantities arrive as DECIMAL(12,4). "Only 3 left" is the message; "Only 3.0000 left" is a
     * database dump.
     */
    private function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }
}
