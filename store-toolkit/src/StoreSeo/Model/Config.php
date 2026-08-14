<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed reader for everything under the `scr1be_seo` section except robots.txt.
 *
 * Robots has its own reader (Model\Robots\Config) because it is the only part of this module that
 * is website-scoped, is consumed outside the storefront request, and needs a default that differs
 * from "read the current store".
 */
class Config
{
    public const XML_PATH_CANONICAL_ENABLED = 'scr1be_seo/canonical/enabled';
    public const XML_PATH_CANONICAL_QUERY_WHITELIST = 'scr1be_seo/canonical/query_whitelist';
    public const XML_PATH_HREFLANG_ENABLED = 'scr1be_seo/hreflang/enabled';
    public const XML_PATH_HREFLANG_X_DEFAULT_STORE = 'scr1be_seo/hreflang/x_default_store';

    /**
     * Query parameters that can never be whitelisted into a canonical, whatever the admin types.
     *
     * `___store` and `___from_store` are the store echo: Magento\Store\Model\Store::getCurrentUrl()
     * appends `___store` to every cross-store link when web/url/use_store is off, and
     * Magento\Store\Block\Switcher::getTargetStorePostData() adds `___from_store` alongside it. A
     * canonical that carried either one would point every store's page at the URL that switches
     * store, which is the exact opposite of what a canonical is for. `uenc` is the base64 return
     * URL Magento\Framework\App\ActionInterface::PARAM_NAME_URL_ENCODED carries, and SID is the
     * legacy session parameter — neither identifies a document.
     */
    public const ALWAYS_DENIED_QUERY_PARAMS = ['___store', '___from_store', 'uenc', 'SID'];

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function isCanonicalEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CANONICAL_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Query parameters allowed to survive into the canonical URL, in no particular order.
     *
     * @return string[]
     */
    public function getCanonicalQueryWhitelist(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_CANONICAL_QUERY_WHITELIST,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $params = array_filter(array_map('trim', explode(',', $raw)), static fn (string $p): bool => $p !== '');

        // The denied list wins over admin input rather than being merged with it.
        $params = array_diff($params, self::ALWAYS_DENIED_QUERY_PARAMS);

        return array_values(array_unique($params));
    }

    public function isHreflangEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_HREFLANG_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Store code nominated as the primary of the hreflang group, or null when the admin left it blank.
     */
    public function getXDefaultStoreCode(): ?string
    {
        $code = trim((string) $this->scopeConfig->getValue(self::XML_PATH_HREFLANG_X_DEFAULT_STORE));

        return $code === '' ? null : $code;
    }
}
