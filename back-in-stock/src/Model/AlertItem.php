<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model;

use Magento\Catalog\Model\Product;

/**
 * One alert, joined to the product it is about.
 *
 * The product model travels with the item rather than being flattened here, because the three
 * consumers want different slices of it: the customer-data section wants a resized image URL, the
 * account page wants the same plus a rendered date, and the GraphQL resolver wants neither — it
 * returns the sku and lets the client resolve media through `products()`, where core's own image
 * resolvers already live. Flattening for the union of those would put a theme-dependent image resize
 * inside a GraphQL response.
 */
final class AlertItem
{
    /**
     * @param array<int, array{code: string, label: string}> $badges Translated labels with the
     *        code the storefront keys on. Order is the order they are shown in.
     */
    public function __construct(
        public readonly int $alertId,
        public readonly Product $product,
        public readonly int $alertStatus,
        public readonly int $popupStatus,
        public readonly ?string $subscribedAt,
        public readonly ?string $restockedAt,
        public readonly float $finalPrice,
        public readonly float $regularPrice,
        public readonly int $ratingSummary,
        public readonly int $reviewsCount,
        public readonly bool $isSalable,
        public readonly bool $requiresConfiguration,
        public readonly QtyRules $qtyRules,
        public readonly array $badges
    ) {
    }

    /**
     * Whether the popup may add this product to the cart without leaving the page.
     *
     * A configurable, a bundle, a grouped product or anything carrying required custom options
     * cannot be added from a card, because the card does not know what options to send. Those
     * degrade to a link to the product page — which is a working path to the same purchase, unlike
     * a button that posts an incomplete `checkout/cart/add` and comes back with an error.
     */
    public function isAddToCartable(): bool
    {
        return $this->isSalable && !$this->requiresConfiguration;
    }

    public function isDiscounted(): bool
    {
        return $this->regularPrice > 0.0 && $this->finalPrice < $this->regularPrice;
    }
}
