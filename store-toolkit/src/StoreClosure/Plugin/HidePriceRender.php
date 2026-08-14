<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\Plugin;

use Magento\Framework\Pricing\Render;
use Magento\Framework\Pricing\SaleableInterface;
use Scr1be\StoreClosure\Model\ClosureState;

/**
 * Removes prices from a closed store's catalogue.
 *
 * One seam covers the whole storefront: `Magento\Framework\Pricing\Render::render()` is what every
 * price template calls — the product page's final price, the card's price box, the tier price
 * table, the swatch's re-render. Its signature is
 * `render($priceCode, SaleableInterface $saleableItem, array $arguments = [])` and it ends in
 * `$priceRender->toHtml()`, so returning an empty string in its place removes the price and the
 * markup around it in one move.
 */
class HidePriceRender
{
    private ClosureState $closureState;

    public function __construct(ClosureState $closureState)
    {
        $this->closureState = $closureState;
    }

    /**
     * `around`, and this is the case where it earns itself.
     *
     * An `after` plugin would let core build the whole render tree — resolve the renderer pool,
     * instantiate the amount renderer, run the price model — and then throw the result away. The
     * point of hiding prices on a closed store is partly that the store is quiet and should stay
     * cheap, so the work is skipped rather than discarded. The trade-off is the usual one: this
     * class must remember to call `$proceed()` on every path that is not the closed one, and there
     * is exactly one such path here.
     *
     * The parameters mirror core's signature exactly, `$priceCode` included: core declares no type
     * for it, so declaring one here would turn a lax call somewhere in a third-party template into
     * a TypeError raised by this plugin.
     *
     * @param callable $proceed
     * @param string $priceCode
     * @param array<string, mixed> $arguments
     */
    public function aroundRender(
        Render $subject,
        callable $proceed,
        $priceCode,
        SaleableInterface $saleableItem,
        array $arguments = []
    ): string {
        if ($this->closureState->shouldHidePrices()) {
            return '';
        }

        return (string) $proceed($priceCode, $saleableItem, $arguments);
    }
}
