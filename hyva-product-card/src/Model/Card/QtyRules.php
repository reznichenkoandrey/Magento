<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model\Card;

/**
 * The three numbers a quantity stepper needs, plus whether fractions are legal.
 *
 * `max` is nullable on purpose: "no ceiling" and "ceiling of zero" are different statements and a
 * float cannot hold both.
 */
class QtyRules implements \JsonSerializable
{
    public function __construct(
        private readonly float $min,
        private readonly float $step,
        private readonly ?float $max,
        private readonly bool $isDecimal
    ) {
    }

    public function getMin(): float
    {
        return $this->min;
    }

    /**
     * Increment the stepper moves by. Always ≥ 0; a product without configured increments reports
     * 1 (or 0.0001 for decimal quantities), because a stepper has to move by *something*.
     */
    public function getStep(): float
    {
        return $this->step;
    }

    public function getMax(): ?float
    {
        return $this->max;
    }

    public function isDecimal(): bool
    {
        return $this->isDecimal;
    }

    /**
     * @return array{min: float, step: float, max: float|null, is_decimal: bool}
     */
    public function toArray(): array
    {
        return [
            'min' => $this->min,
            'step' => $this->step,
            'max' => $this->max,
            'is_decimal' => $this->isDecimal,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
