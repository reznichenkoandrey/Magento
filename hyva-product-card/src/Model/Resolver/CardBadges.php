<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model\Resolver;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Scr1be\HyvaProductCard\Model\Card\Badge;
use Scr1be\HyvaProductCard\Model\Card\BadgeResolver;
use Scr1be\HyvaProductCard\Model\Card\StockPresenter;

/**
 * `ProductInterface.card_badges`, resolved by the same class the phtml renderer uses.
 *
 * The badge list is the part of a card most likely to be re-implemented in a headless frontend —
 * it looks like three `if`s — and the re-implementation is always subtly different: it reads
 * `special_price` instead of the rendered final price, or it forgets that a product with no
 * `news_from_date` is not new. Exposing the resolved list keeps that decision on the server.
 */
class CardBadges implements ResolverInterface
{
    public function __construct(
        private readonly BadgeResolver $badgeResolver,
        private readonly StockPresenter $stockPresenter
    ) {
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ): array {
        $product = $value['model'] ?? null;
        if (!$product instanceof Product) {
            throw new LocalizedException(__('"model" value should be specified'));
        }

        // The low-stock badge needs a quantity, and a GraphQL product list has no more of one than
        // a listing page does. Passing null keeps the resolver honest: clients that want the
        // urgency signal read `only_x_left_in_stock`, which core resolves with its own query.
        $stock = $this->stockPresenter->present(
            (bool) $product->isSalable(),
            null,
            (int) $product->getStoreId()
        );

        return array_map(
            static fn (Badge $badge): array => $badge->toArray(),
            $this->badgeResolver->resolve($product, $stock)
        );
    }
}
