<?php
declare(strict_types=1);

namespace Scr1be\SearchAutocomplete\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The admin's existing search settings, reused rather than duplicated.
 *
 * A module that invents its own "autocomplete limit" leaves a merchant with two fields that both
 * claim to control the same thing, and a support ticket the first time they disagree. These are the
 * paths core's own autocomplete reads: `catalog/search/autocomplete_limit` is
 * `Magento\CatalogSearch\Model\Autocomplete\DataProvider::CONFIG_AUTOCOMPLETE_LIMIT`, and
 * `min_query_length` / `max_query_length` are declared alongside it in
 * `Magento_CatalogSearch/etc/config.xml` (defaults 3 and 128).
 */
class Config
{
    private const XML_PATH_AUTOCOMPLETE_LIMIT = 'catalog/search/autocomplete_limit';
    private const XML_PATH_MIN_QUERY_LENGTH = 'catalog/search/min_query_length';
    private const XML_PATH_MAX_QUERY_LENGTH = 'catalog/search/max_query_length';

    /**
     * Used when the setting is blank. Core's `DataProvider` treats a falsy limit as "no limit"; an
     * autocomplete endpoint that can be asked for every matching product is a denial-of-service
     * button, so this module treats it as a default instead.
     */
    public const DEFAULT_LIMIT = 8;

    private const DEFAULT_MIN_QUERY_LENGTH = 3;
    private const DEFAULT_MAX_QUERY_LENGTH = 128;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * How many suggestions each provider may return.
     *
     * @param int $storeId
     * @return int
     */
    public function getLimit(int $storeId): int
    {
        $limit = (int)$this->scopeConfig->getValue(
            self::XML_PATH_AUTOCOMPLETE_LIMIT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $limit > 0 ? $limit : self::DEFAULT_LIMIT;
    }

    /**
     * @param int $storeId
     * @return int
     */
    public function getMinQueryLength(int $storeId): int
    {
        $length = (int)$this->scopeConfig->getValue(
            self::XML_PATH_MIN_QUERY_LENGTH,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $length > 0 ? $length : self::DEFAULT_MIN_QUERY_LENGTH;
    }

    /**
     * @param int $storeId
     * @return int
     */
    public function getMaxQueryLength(int $storeId): int
    {
        $length = (int)$this->scopeConfig->getValue(
            self::XML_PATH_MAX_QUERY_LENGTH,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $length > 0 ? $length : self::DEFAULT_MAX_QUERY_LENGTH;
    }
}
