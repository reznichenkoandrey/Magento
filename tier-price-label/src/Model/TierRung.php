<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\Model;

/**
 * One step of a tier-price ladder, normalised for both PHP and JS consumers.
 *
 * Deliberately a plain value object rather than a Magento DataObject: the ladder is read
 * dozens of times per listing page, and the magic-getter machinery of DataObject is pure
 * overhead for four immutable scalars.
 */
final class TierRung
{
    public function __construct(
        private readonly float $qty,
        private readonly float $value,
        private readonly string $formattedValue,
        private readonly ?float $percentage
    ) {
    }

    public function getQty(): float
    {
        return $this->qty;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function getFormattedValue(): string
    {
        return $this->formattedValue;
    }

    /**
     * Percentage discount as configured in the admin, or null for a fixed-amount rung.
     */
    public function getPercentage(): ?float
    {
        return $this->percentage;
    }

    /**
     * @return array{qty: float, value: float, formatted: string, percentage: float|null}
     */
    public function toArray(): array
    {
        return [
            'qty' => $this->qty,
            'value' => $this->value,
            'formatted' => $this->formattedValue,
            'percentage' => $this->percentage,
        ];
    }
}
