<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model\Card;

/**
 * Everything one card knows, in the shape every renderer agrees on.
 *
 * This object is the contract. The server template reads it through getters, the Alpine grid reads
 * `toArray()` out of a JSON island, the widget reads whichever of the two it was configured for,
 * and the GraphQL resolvers return slices of it. Adding a field here is the only way to add a field
 * to the card, which is the entire point: four renderers cannot drift apart if there is nothing to
 * drift from.
 */
class CardData implements \JsonSerializable
{
    /**
     * @param Badge[] $badges
     * @param array<string, mixed> $analytics GA4 item payload; empty when analytics are off.
     */
    public function __construct(
        private readonly int $productId,
        private readonly string $sku,
        private readonly string $name,
        private readonly string $url,
        private readonly array $badges,
        private readonly ImageSource $image,
        private readonly StockPresentation $stock,
        private readonly QtyRules $qtyRules,
        private readonly array $analytics
    ) {
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return Badge[]
     */
    public function getBadges(): array
    {
        return $this->badges;
    }

    public function getImage(): ImageSource
    {
        return $this->image;
    }

    public function getStock(): StockPresentation
    {
        return $this->stock;
    }

    public function getQtyRules(): QtyRules
    {
        return $this->qtyRules;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAnalytics(): array
    {
        return $this->analytics;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->productId,
            'sku' => $this->sku,
            'name' => $this->name,
            'url' => $this->url,
            'badges' => array_map(static fn (Badge $badge): array => $badge->toArray(), $this->badges),
            'image' => $this->image->toArray(),
            'stock' => $this->stock->toArray(),
            'qty_rules' => $this->qtyRules->toArray(),
            'analytics' => $this->analytics,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
