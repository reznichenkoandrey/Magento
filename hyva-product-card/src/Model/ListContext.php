<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The "where was this card" half of a GA4 event.
 *
 * GA4's list attribution only works if `item_list_id` is stable across the impression, the click
 * and the add-to-cart — three events fired by three different pieces of JavaScript. Letting each
 * renderer compose its own id is how attribution quietly rots: the client grid says
 * `category-12`, the server card says `Category 12`, and the funnel splits in two.
 *
 * So the id is derived once, here, from the request, and travels with the card payload.
 */
class ListContext
{
    private const LIST_SEARCH = 'search_results';
    private const LIST_CATEGORY_PREFIX = 'category_';
    private const LIST_WIDGET_PREFIX = 'widget_';
    private const LIST_OTHER = 'product_list';

    private const ACTION_SEARCH_RESULT = 'catalogsearch_result_index';
    private const ACTION_ADVANCED_RESULT = 'catalogsearch_advanced_result';

    /**
     * @var array{id: string, name: string}|null Memoised: every card on the page asks.
     */
    private ?array $resolved = null;

    /**
     * The concrete `Request\Http`, not `RequestInterface`: `getFullActionName()` is declared on the
     * HTTP request, not on the interface. Type-hinting the interface would work at runtime and lie
     * in the signature.
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly Registry $registry,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    /**
     * @param string|null $override Widgets know their own list identity; nothing else does.
     * @return array{item_list_id: string, item_list_name: string}
     */
    public function get(?string $override = null): array
    {
        if ($override !== null && $override !== '') {
            return [
                'item_list_id' => self::LIST_WIDGET_PREFIX . $this->slug($override),
                'item_list_name' => $override,
            ];
        }

        $this->resolved ??= $this->resolve();

        return [
            'item_list_id' => $this->resolved['id'],
            'item_list_name' => $this->resolved['name'],
        ];
    }

    /**
     * @param string|null $listOverride Passed through from the calling renderer, see get().
     * @return array<string, mixed> One GA4 `items[]` entry.
     */
    public function toItemPayload(Product $product, int $index, ?string $listOverride = null): array
    {
        $context = $this->get($listOverride);

        return $context + [
            'item_id' => (string) $product->getSku(),
            'item_name' => (string) $product->getName(),
            // GA4 wants the position within the list, 1-based; renderers count from 0.
            'index' => $index + 1,
            'price' => $this->getPrice($product),
            'currency' => $this->getCurrencyCode(),
        ];
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->config->isGa4Enabled($storeId);
    }

    /**
     * @return array{id: string, name: string}
     */
    private function resolve(): array
    {
        $action = (string) $this->request->getFullActionName();

        if ($action === self::ACTION_SEARCH_RESULT || $action === self::ACTION_ADVANCED_RESULT) {
            return ['id' => self::LIST_SEARCH, 'name' => (string) __('Search results')];
        }

        // The same registry key core's own `Helper\Product\ProductList::getDefaultSortField()`
        // reads. Going through the layer resolver instead would *create* a current category on
        // pages that have none, which is a heavier lie than reading a deprecated registry key.
        $category = $this->registry->registry('current_category');
        if ($category !== null && $category->getId()) {
            return [
                'id' => self::LIST_CATEGORY_PREFIX . $category->getId(),
                'name' => (string) $category->getName(),
            ];
        }

        return ['id' => self::LIST_OTHER, 'name' => (string) __('Product list')];
    }

    private function getPrice(Product $product): float
    {
        try {
            return round((float) $product->getPriceInfo()->getPrice(FinalPrice::PRICE_CODE)->getAmount()->getValue(), 2);
        } catch (\InvalidArgumentException) {
            return 0.0;
        }
    }

    private function getCurrencyCode(): string
    {
        try {
            return (string) $this->storeManager->getStore()->getCurrentCurrencyCode();
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            return '';
        }
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $value) ?? '', '_'));

        return $slug !== '' ? $slug : self::LIST_OTHER;
    }
}
