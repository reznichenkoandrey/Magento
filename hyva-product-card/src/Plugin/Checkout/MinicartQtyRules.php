<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Plugin\Checkout;

use Magento\Checkout\CustomerData\ItemPoolInterface;
use Magento\Quote\Model\Quote\Item;
use Scr1be\HyvaProductCard\Model\Card\QtyRuleResolver;
use Scr1be\HyvaProductCard\Model\Config;

/**
 * The minicart's quantity field obeys the same rules as the card's stepper.
 *
 * Without this, a product sold in packs of six has a stepper that steps by six on the listing, a
 * PDP that steps by six — and a minicart input that lets the shopper type 7 and then rejects the
 * cart at checkout with a message about quantity increments. The rules were always available; they
 * just never reached the drawer.
 *
 * Same seam and same reasoning as {@see MinicartDiscount}: one `after` plugin on
 * `ItemPoolInterface::getItemData()` covers every item renderer in the pool. The rules come from
 * the resolver the card and the PDP use, which is the entire point — a second implementation would
 * eventually disagree with the first, and the disagreement would surface as a rejected checkout.
 */
class MinicartQtyRules
{
    public function __construct(
        private readonly QtyRuleResolver $qtyRuleResolver,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function afterGetItemData(ItemPoolInterface $subject, array $result, Item $item): array
    {
        if (!$this->config->isEnabled()) {
            return $result;
        }

        $product = $item->getProduct();
        $productId = $product !== null ? (int) $product->getId() : 0;
        if ($productId <= 0) {
            return $result;
        }

        $result['qty_rules'] = $this->qtyRuleResolver->resolve($productId)->toArray();

        return $result;
    }
}
