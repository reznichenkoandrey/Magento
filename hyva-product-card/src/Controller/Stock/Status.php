<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Controller\Stock;

use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Model\StockRegistryPreloader;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\HyvaProductCard\Model\Card\StockPresenter;
use Scr1be\HyvaProductCard\Model\Config;

/**
 * Live availability for a page of cards, in one request that a CDN may keep.
 *
 * A card's HTML is cached twice over: Hyvä caches the item block (see `ProductListItem::
 * renderItemHtml()`, which sets `cache_lifetime` from `hyva_theme_catalog/developer/cache/
 * product_list_item_block_cache_lifetime`, one hour by default) and the FPC caches the page around
 * it. Both are correct and both mean the stock line printed into the HTML is as old as the cache
 * entry. This endpoint is how the card gets a current answer without either cache having to be
 * short-lived.
 *
 * The response is deliberately identical for every visitor — no prices, no customer data, no
 * session — so it can be `public, max-age=N`. Two things had to be true for that:
 *
 * 1. **No session may start.** {@see \Scr1be\HyvaProductCard\Plugin\Session\SuppressStockEndpointSession}
 *    keeps `SessionManager::start()` from running here, which removes both the `PHPSESSID`
 *    `Set-Cookie` and — because the HTTP context stays empty — the `X-Magento-Vary` cookie that
 *    `Response\Http::sendVary()` would otherwise write. The shipped `varnish7.vcl` marks a response
 *    uncacheable when it sets `X-Magento-Vary` for a request that did not send one.
 * 2. **The headers must say `public`.** The same VCL treats any `Cache-Control: private` response
 *    as uncacheable, which is what a Magento controller produces if you leave it alone.
 */
class Status implements HttpGetActionInterface
{
    /**
     * A card grid is at most a page of products. Anything larger is somebody enumerating the
     * catalogue through a cacheable endpoint, one URL per permutation.
     */
    private const MAX_IDS = 60;

    private const PARAM_IDS = 'ids';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $resultJsonFactory,
        private readonly StockRegistryPreloader $preloader,
        private readonly \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        private readonly StockPresenter $stockPresenter,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    /**
     * @return ResultInterface|ResponseInterface
     */
    public function execute()
    {
        $storeId = $this->getStoreId();
        $result = $this->resultJsonFactory->create();

        $productIds = $this->readIds();
        if ($productIds === []) {
            return $result->setData(['items' => []]);
        }

        // One query for the page, then every lookup below is a registry hit.
        $this->preloader->preloadStockStatuses($productIds);

        $items = [];
        foreach ($productIds as $productId) {
            $status = $this->stockRegistry->getStockStatus($productId);
            $items[$productId] = $this->stockPresenter->present(
                (int) $status->getStockStatus() === StockStatusInterface::STATUS_IN_STOCK,
                $this->readQty($status),
                $storeId
            )->toArray();
        }

        $ttl = $this->config->getStockEndpointTtl($storeId);
        if ($ttl > 0) {
            $result->setHeader('cache-control', sprintf('public, max-age=%d, s-maxage=%d', $ttl, $ttl), true);
        } else {
            $result->setHeader('cache-control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        }

        return $result->setData(['items' => $items]);
    }

    /**
     * @return int[]
     */
    private function readIds(): array
    {
        $raw = (string) $this->request->getParam(self::PARAM_IDS, '');

        $ids = [];
        foreach (explode(',', $raw) as $candidate) {
            $id = (int) trim($candidate);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        // Sorted so that two cards asking for the same page in a different order share one cache
        // entry instead of minting two.
        ksort($ids);

        return array_slice(array_values($ids), 0, self::MAX_IDS);
    }

    /**
     * Quantity is what turns "In stock" into "Only 3 left", so it is worth being careful: a status
     * row with no `qty` is not a product with zero left, it is a product whose quantity nobody
     * tracks (`manage_stock` off, or a source that does not report one).
     */
    private function readQty(StockStatusInterface $status): ?float
    {
        $qty = $status->getQty();

        return is_numeric($qty) ? (float) $qty : null;
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
