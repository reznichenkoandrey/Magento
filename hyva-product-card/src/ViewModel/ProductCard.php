<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\HyvaProductCard\Model\Card\CardData;
use Scr1be\HyvaProductCard\Model\Card\CardDataBuilder;
use Scr1be\HyvaProductCard\Model\Config;
use Scr1be\HyvaProductCard\Model\LayeredNavLabels;
use Scr1be\HyvaProductCard\Model\ListContext;
use Scr1be\HyvaProductCard\Model\ToolbarDefaults;

/**
 * The module's public surface for templates. Everything a card renders comes through here.
 *
 * Deliberately a façade over six collaborators rather than six ViewModels: a template that has to
 * ask four objects to draw one card is a template that will eventually ask three of them and forget
 * the fourth. The facade also owns the JSON boundary — `getGridPayload()` is the *only* place card
 * state is serialised, so the Alpine renderer and the phtml renderer cannot describe the same card
 * with different keys.
 */
class ProductCard implements ArgumentInterface
{
    private const ROUTE_STOCK_STATUS = 'scr1be_card/stock/status';
    private const ROUTE_MESSAGE_DRAIN = 'scr1be_card/message/drain';

    public function __construct(
        private readonly CardDataBuilder $cardDataBuilder,
        private readonly LayeredNavLabels $layeredNavLabels,
        private readonly ToolbarDefaults $toolbarDefaults,
        private readonly ListContext $listContext,
        private readonly Config $config,
        private readonly UrlInterface $url,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled($this->getStoreId());
    }

    public function getCard(Product $product, string $imageId, int $index = 0, ?string $listName = null): CardData
    {
        return $this->cardDataBuilder->build($product, $imageId, $index, $listName);
    }

    /**
     * @param Product[] $products
     * @return CardData[] Keyed by product id.
     */
    public function getCards(array $products, string $imageId, ?string $listName = null): array
    {
        return $this->cardDataBuilder->buildMany($products, $imageId, $listName);
    }

    /**
     * The JSON island the Alpine grid hydrates from.
     *
     * `JSON_HEX_TAG` matters more than it looks: product names are merchant-supplied and travel
     * inside a `<script>` element, so a name containing `</script>` would otherwise close the
     * element and turn the rest of the payload into markup.
     *
     * @param Product[] $products
     */
    public function getGridPayload(array $products, string $imageId, ?string $listName = null): string
    {
        $cards = [];
        foreach ($this->getCards($products, $imageId, $listName) as $card) {
            $cards[] = $card->toArray();
        }

        return (string) json_encode(
            [
                'cards' => $cards,
                'toolbar' => $this->toolbarDefaults->toArray(),
                'filter_labels' => $this->layeredNavLabels->getMap(),
                'endpoints' => [
                    'stock' => $this->getStockEndpointUrl(),
                    'drain' => $this->getMessageDrainUrl(),
                ],
                'ga4' => $this->isGa4Enabled(),
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Config the Alpine component needs when the cards themselves were rendered server-side and
     * there is no grid payload to read.
     */
    public function getCardConfigJson(): string
    {
        return (string) json_encode(
            [
                'endpoints' => [
                    'stock' => $this->getStockEndpointUrl(),
                    'drain' => $this->getMessageDrainUrl(),
                ],
                'ga4' => $this->isGa4Enabled(),
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * @return array<string, string>
     */
    public function getFilterLabels(): array
    {
        return $this->layeredNavLabels->getMap();
    }

    /**
     * @return array{sort: string, direction: string}
     */
    public function getToolbarDefaults(): array
    {
        return $this->toolbarDefaults->toArray();
    }

    /**
     * @return array{item_list_id: string, item_list_name: string}
     */
    public function getListContext(?string $listName = null): array
    {
        return $this->listContext->get($listName);
    }

    public function isGa4Enabled(): bool
    {
        return $this->config->isGa4Enabled($this->getStoreId());
    }

    public function getStockEndpointUrl(): string
    {
        return $this->url->getUrl(self::ROUTE_STOCK_STATUS);
    }

    public function getMessageDrainUrl(): string
    {
        return $this->url->getUrl(self::ROUTE_MESSAGE_DRAIN);
    }

    private function getStoreId(): ?int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            return null;
        }
    }
}
