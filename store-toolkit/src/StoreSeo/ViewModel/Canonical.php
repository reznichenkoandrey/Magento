<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\ViewModel;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\StoreSeo\Model\Canonical\UrlBuilder;
use Scr1be\StoreSeo\Model\Config;

/**
 * The I/O half of the canonical: current store, current request, current configuration.
 *
 * Memoised because the head block is not the only caller — an Open Graph `og:url` tag, a JSON-LD
 * block and a paginated `rel=prev/next` pair all want the same string, and each of them asking
 * would re-read config and rebuild the query for a value that cannot change inside one request.
 */
class Canonical implements ArgumentInterface
{
    private StoreManagerInterface $storeManager;

    /**
     * The concrete HTTP request, not RequestInterface: neither getPathInfo() nor getQueryValue() is
     * on the interface, and both are load-bearing here — the first is the only path with the store
     * code already stripped, the second is the only one that means "the query string" rather than
     * "route parameters, query and POST body merged".
     */
    private HttpRequest $request;

    private Config $config;

    private UrlBuilder $urlBuilder;

    private bool $resolved = false;

    private ?string $canonicalUrl = null;

    public function __construct(
        StoreManagerInterface $storeManager,
        HttpRequest $request,
        Config $config,
        UrlBuilder $urlBuilder
    ) {
        $this->storeManager = $storeManager;
        $this->request = $request;
        $this->config = $config;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Null when canonicals are switched off for this store, or when the store cannot be resolved.
     */
    public function getCanonicalUrl(): ?string
    {
        if ($this->resolved) {
            return $this->canonicalUrl;
        }

        $this->resolved = true;

        try {
            $store = $this->storeManager->getStore();
        } catch (NoSuchEntityException $e) {
            return $this->canonicalUrl;
        }

        $storeId = (int) $store->getId();

        if (!$this->config->isCanonicalEnabled($storeId) || !$store instanceof Store) {
            return $this->canonicalUrl;
        }

        $this->canonicalUrl = $this->urlBuilder->build(
            $store->getBaseUrl(UrlInterface::URL_TYPE_LINK),
            (string) $this->request->getPathInfo(),
            $this->getQueryParams(),
            $this->config->getCanonicalQueryWhitelist($storeId)
        );

        return $this->canonicalUrl;
    }

    /**
     * @return array<string, mixed>
     */
    private function getQueryParams(): array
    {
        $params = $this->request->getQueryValue();

        return is_array($params) ? $params : [];
    }
}
