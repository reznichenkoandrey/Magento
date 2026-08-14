<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model;

/**
 * What `cataloginventory_stock_item` says a valid quantity looks like for one product.
 *
 * These four numbers exist so the popup's quantity control can only produce a quantity the cart will
 * accept. Without them the inline add-to-cart is a guess: a product with `min_sale_qty` of 6 gets a
 * "1" posted at it, `Magento\CatalogInventory\Model\Quote\Item\QuantityValidator` rejects the quote
 * item, and the customer's reward for clicking the notification they asked for is an error message.
 */
final class QtyRules
{
    public function __construct(
        public readonly float $minQty,
        public readonly float $maxQty,
        public readonly float $increment,
        public readonly bool $isDecimal
    ) {
    }

    /**
     * The rules that apply when the product has no stock item of its own — one at a time, no ceiling
     * worth enforcing client-side, whole units.
     */
    public static function unrestricted(): self
    {
        return new self(1.0, 0.0, 0.0, false);
    }

    /**
     * The quantity the popup's control starts on.
     *
     * The minimum when there is one, rounded up to the next increment when both apply — a product
     * with a minimum of 3 and an increment of 2 starts at 4, because 3 is not a quantity it sells.
     */
    public function getStartQty(): float
    {
        $qty = $this->minQty > 0 ? $this->minQty : 1.0;

        if ($this->increment > 0) {
            $qty = ceil($qty / $this->increment) * $this->increment;
        }

        return $this->isDecimal ? $qty : (float)(int)ceil($qty);
    }

    /**
     * @return array{min: float, max: float, increment: float, decimal: bool, start: float}
     */
    public function toArray(): array
    {
        return [
            'min' => $this->minQty,
            'max' => $this->maxQty,
            'increment' => $this->increment,
            'decimal' => $this->isDecimal,
            'start' => $this->getStartQty(),
        ];
    }
}
