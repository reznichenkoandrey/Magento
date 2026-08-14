<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model\Card;

use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Turns a product's stock item into the min/step/max a stepper can be driven by.
 *
 * The tempting implementation reads `use_config_min_sale_qty` and branches to
 * `StockConfigurationInterface` when it is set. That ladder is already implemented — and
 * implemented better — inside the stock item model itself: `Magento\CatalogInventory\Model\Stock
 * \Item::getMinSaleQty()` consults `getUseConfigMinSaleQty()` and, when it is on, asks
 * `StockConfigurationInterface::getMinSaleQty($storeId, $customerGroupId)`, i.e. it carries the
 * *customer-group* dimension of the minimum sale quantity that a naive re-implementation drops.
 * The same is true of `getMaxSaleQty()`, `getEnableQtyIncrements()` and `getQtyIncrements()`.
 *
 * So this class reads the getters and adds nothing except the two conversions a browser needs:
 * `getQtyIncrements()` returns `false` when increments are off (verified in that method — it nulls
 * out any value ≤ 0 before returning), and a stepper cannot step by `false`.
 *
 * No scope argument, on purpose. `StockRegistry::getStockItem($productId, $scopeId)` reassigns
 * `$scopeId` to `StockConfigurationInterface::getDefaultScopeId()` on its first line and never
 * looks at what the caller passed — so a scope parameter here would document a capability the core
 * API does not have. It is also what makes {@see \Scr1be\HyvaProductCard\Observer\PreloadQtyRules}
 * work: the preloader writes into the registry under that same default scope id, so every lookup
 * below is a memory hit.
 */
class QtyRuleResolver
{
    /**
     * Fallback increment when the product has none configured. Decimal-quantity products get a
     * fine-grained one so a stepper on a 0.5 kg product is not forced up to whole units.
     */
    private const DEFAULT_STEP = 1.0;
    private const DEFAULT_DECIMAL_STEP = 0.0001;

    /** `cataloginventory_stock_item` stores quantities as DECIMAL(12,4). */
    private const QTY_SCALE = 4;

    /** @var array<int, QtyRules> Per-request memo: the card asks, then the minicart asks again. */
    private array $memo = [];

    public function __construct(private readonly StockRegistryInterface $stockRegistry)
    {
    }

    public function resolve(int $productId): QtyRules
    {
        if (isset($this->memo[$productId])) {
            return $this->memo[$productId];
        }

        try {
            $stockItem = $this->stockRegistry->getStockItem($productId);
        } catch (NoSuchEntityException) {
            // Core's implementation answers with an empty stock item rather than throwing, and an
            // empty item already degrades to the defaults below. The catch is here for the
            // replacements integrators put behind this interface, which do throw.
            return $this->memo[$productId] = $this->defaults();
        }

        return $this->memo[$productId] = $this->fromStockItem($stockItem);
    }

    public function fromStockItem(StockItemInterface $stockItem): QtyRules
    {
        $isDecimal = (bool) $stockItem->getIsQtyDecimal();

        $increments = $stockItem->getQtyIncrements();
        $step = is_numeric($increments) && (float) $increments > 0
            ? (float) $increments
            : $this->defaultStep($isDecimal);

        $min = (float) $stockItem->getMinSaleQty();
        if ($min <= 0) {
            $min = $step;
        }

        // A zero maximum is how `cataloginventory_stock_item` spells "no ceiling"; passing it
        // through as a number would make every stepper refuse the first click.
        $max = (float) $stockItem->getMaxSaleQty();

        return new QtyRules(
            $this->alignUp($min, $step),
            $step,
            $max > 0 ? $this->alignDown($max, $step) : null,
            $isDecimal
        );
    }

    /**
     * Both bounds are snapped onto the increment ladder before anyone renders them.
     *
     * A legal quantity is a whole multiple of `qty_increments` measured from **zero** — core
     * validates exactly that in `StockStateProvider::checkQtyIncrements()`, which errors unless
     * `getExactDivision($qty, $qtyIncrements)` is 0. So "minimum 10, increments of 6" does not mean
     * 10, 16, 22: it means the smallest legal quantity is 12. Hyvä's own PDP quantity field reaches
     * the same conclusion in its template (`ceil($minSalesQty / $step) * $step`), and core's
     * `suggestQty()` does it with `ceil($minQty / $qtyIncrements) * $qtyIncrements`. Doing it here
     * instead of in a template is what keeps the card, the PDP, the minicart and a headless client
     * from each reaching it separately — or, worse, three of them reaching it and one not.
     *
     * When a product's aligned ceiling lands below its aligned minimum there is no purchasable
     * quantity at all. That is reported as it is; inventing a buyable number would move the
     * rejection from the stepper to the checkout.
     */
    private function alignUp(float $value, float $step): float
    {
        return $this->round(ceil($this->round($value / $step)) * $step);
    }

    private function alignDown(float $value, float $step): float
    {
        return $this->round(floor($this->round($value / $step)) * $step);
    }

    /**
     * Quantities are DECIMAL(12,4). Rounding at that precision before the ceil/floor stops
     * 12 / 0.1 = 119.99999999999999 from snapping a legal minimum up by a whole step.
     */
    private function round(float $value): float
    {
        return round($value, self::QTY_SCALE);
    }

    private function defaults(): QtyRules
    {
        return new QtyRules(self::DEFAULT_STEP, self::DEFAULT_STEP, null, false);
    }

    private function defaultStep(bool $isDecimal): float
    {
        return $isDecimal ? self::DEFAULT_DECIMAL_STEP : self::DEFAULT_STEP;
    }
}
