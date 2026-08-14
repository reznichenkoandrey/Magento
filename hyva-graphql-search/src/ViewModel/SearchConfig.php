<?php
declare(strict_types=1);

namespace Scr1be\HyvaGraphqlSearch\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Centralises every runtime value the instant-search bar needs: GraphQL endpoint, search-results
 * route, product URL suffix, minimum query length. All are read from Magento config / URL helpers
 * so the search bar works on any install — multistore, custom suffixes, moved routes.
 */
class SearchConfig implements ArgumentInterface
{
    private const PATH_MIN_QUERY     = 'catalog/search/min_query_length';
    private const PATH_URL_SUFFIX    = 'catalog/seo/product_url_suffix';
    private const SEARCH_RESULT_PATH = 'catalogsearch/result/';
    private const GRAPHQL_PATH       = 'graphql';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
    ) {
    }

    /**
     * URL_TYPE_WEB, not URL_TYPE_LINK. A link URL runs through Store::_updatePathUseStoreView(),
     * which appends the store code to the path whenever "Add Store Code to URLs" is on — a
     * common setting on single-domain multistore installs. GraphQL is not part of the storefront
     * router: Magento_GraphQl registers it as its own area with frontName "graphql", always
     * served at <base>/graphql, never under a store-code prefix. Building it from a link URL
     * therefore 404s every search on exactly the installs that need scoping most.
     *
     * The store is still selected — by the Store header the search bar sends, which is the
     * contract GraphQL actually uses for scope.
     */
    public function getGraphqlEndpoint(): string
    {
        $base = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB, true);

        return rtrim($base, '/') . '/' . self::GRAPHQL_PATH;
    }

    public function getSearchResultUrl(): string
    {
        return $this->urlBuilder->getUrl(self::SEARCH_RESULT_PATH);
    }

    public function getProductUrlSuffix(): string
    {
        return (string) ($this->scopeConfig->getValue(self::PATH_URL_SUFFIX, ScopeInterface::SCOPE_STORE) ?? '');
    }

    public function getMinQueryLength(): int
    {
        return (int) ($this->scopeConfig->getValue(self::PATH_MIN_QUERY, ScopeInterface::SCOPE_STORE) ?? 3);
    }
}
