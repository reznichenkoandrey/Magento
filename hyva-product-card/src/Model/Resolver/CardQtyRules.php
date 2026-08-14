<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model\Resolver;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Scr1be\HyvaProductCard\Model\Card\QtyRuleResolver;

/**
 * `ProductInterface.qty_rules`.
 *
 * The rules a headless cart has to obey are the same ones the storefront stepper obeys, and they
 * are not derivable from anything else in the schema: core exposes stock *status* and, with the
 * threshold set, a remaining quantity — never the sale-quantity ladder. A PWA that does not have
 * these ends up letting a shopper order 7 of a product sold in packs of 6 and discovering it at
 * checkout.
 *
 * No query per product: {@see \Scr1be\HyvaProductCard\Observer\PreloadQtyRules} is registered in
 * the `graphql` area too, so the stock registry is already warm by the time the first field
 * resolves.
 */
class CardQtyRules implements ResolverInterface
{
    public function __construct(private readonly QtyRuleResolver $qtyRuleResolver)
    {
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

        return $this->qtyRuleResolver->resolve((int) $product->getId())->toArray();
    }
}
