<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model\Card;

/**
 * One badge, in the shape all four renderers agree on.
 *
 * `code` is the stable machine name (templates key their Tailwind classes off it, GraphQL clients
 * key their own styling off it); `label` is translated copy that may change per store without any
 * consumer noticing.
 */
class Badge implements \JsonSerializable
{
    public const CODE_NEW = 'new';
    public const CODE_SALE = 'sale';
    public const CODE_LOW_STOCK = 'low_stock';

    public function __construct(
        private readonly string $code,
        private readonly string $label,
        private readonly int $priority,
        private readonly ?float $value = null
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Lower sorts first. Renderers with room for one badge take the head of the list.
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * The number behind the badge when there is one — discount percentage for `sale`, remaining
     * quantity for `low_stock`. Null for badges that are purely categorical.
     */
    public function getValue(): ?float
    {
        return $this->value;
    }

    /**
     * @return array{code: string, label: string, priority: int, value: float|null}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'priority' => $this->priority,
            'value' => $this->value,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
