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

    public function getGraphqlEndpoint(): string
    {
        $base = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_LINK, true);
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
