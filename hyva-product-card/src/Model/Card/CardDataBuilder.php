<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model\Card;

use Magento\Catalog\Model\Product;
use Scr1be\HyvaProductCard\Model\Config;
use Scr1be\HyvaProductCard\Model\ListContext;

/**
 * Assembles a {@see CardData} from a loaded product. The only constructor of card state.
 *
 * Note what is *not* here: no queries. Every collaborator either reads attributes the product
 * already carries or reads a registry the bulk observer warmed before render started. A builder
 * that lazily fetched what it was missing would be indistinguishable from correct on a PDP and a
 * disaster on a 24-card grid, which is exactly the failure mode this module exists to avoid.
 */
class CardDataBuilder
{
    public function __construct(
        private readonly BadgeResolver $badgeResolver,
        private readonly MediaResolver $mediaResolver,
        private readonly StockPresenter $stockPresenter,
        private readonly QtyRuleResolver $qtyRuleResolver,
        private readonly ListContext $listContext,
        private readonly Config $config
    ) {
    }

    /**
     * @param string $imageId A view.xml image id, e.g. `category_page_grid`.
     * @param int $index Position within the list, 0-based — GA4 wants it and nothing else does.
     * @param string|null $listOverride Widget list name, when the caller is a widget.
     */
    public function build(
        Product $product,
        string $imageId,
        int $index = 0,
        ?string $listOverride = null
    ): CardData {
        $storeId = (int) $product->getStoreId();

        // `isSalable()` is the product type's own answer and is already loaded on any collection
        // that went through the stock status index. Quantity is deliberately not asked for: a
        // listing card that wanted one would have to query per card, and the stock endpoint exists
        // precisely so it does not have to.
        $stock = $this->stockPresenter->present((bool) $product->isSalable(), null, $storeId);

        return new CardData(
            (int) $product->getId(),
            (string) $product->getSku(),
            (string) $product->getName(),
            (string) $product->getProductUrl(),
            $this->badgeResolver->resolve($product, $stock),
            $this->mediaResolver->resolve($product, $imageId),
            $stock,
            $this->qtyRuleResolver->resolve((int) $product->getId()),
            $this->config->isGa4Enabled($storeId)
                ? $this->listContext->toItemPayload($product, $index, $listOverride)
                : []
        );
    }

    /**
     * @param Product[] $products
     * @return CardData[] Keyed by product id, so a renderer can look one up without a scan.
     */
    public function buildMany(array $products, string $imageId, ?string $listOverride = null): array
    {
        $cards = [];
        $index = 0;
        foreach ($products as $product) {
            $cards[(int) $product->getId()] = $this->build($product, $imageId, $index, $listOverride);
            $index++;
        }

        return $cards;
    }
}
