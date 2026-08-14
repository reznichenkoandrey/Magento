<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model\Card;

/**
 * What a card is allowed to say about availability.
 *
 * `salableQty` is nullable because most surfaces genuinely do not know it: a listing card reads a
 * stock *status* index, not a quantity. Modelling the unknown as `0` would make every card on a
 * category page claim the product is nearly gone.
 */
class StockPresentation implements \JsonSerializable
{
    public function __construct(
        private readonly bool $inStock,
        private readonly string $label,
        private readonly bool $isLow,
        private readonly ?float $salableQty
    ) {
    }

    public function isInStock(): bool
    {
        return $this->inStock;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isLow(): bool
    {
        return $this->isLow;
    }

    public function getSalableQty(): ?float
    {
        return $this->salableQty;
    }

    /**
     * @return array{in_stock: bool, label: string, is_low: bool, salable_qty: float|null}
     */
    public function toArray(): array
    {
        return [
            'in_stock' => $this->inStock,
            'label' => $this->label,
            'is_low' => $this->isLow,
            'salable_qty' => $this->salableQty,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
