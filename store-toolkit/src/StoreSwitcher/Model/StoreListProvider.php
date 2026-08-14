<?php
declare(strict_types=1);

namespace Scr1be\StoreSwitcher\Model;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\ViewModel\SwitcherUrlProvider;

/**
 * The switchable stores, in display order.
 *
 * Every active store in the installation, not only the ones under the current website. A store
 * cluster with one website per country is the normal shape of the setup this toolkit is for, and
 * a switcher scoped to the current website could not reach the stores the hreflang tags on the
 * same page advertise — which is the one thing a visitor who sees a flag row expects it to do.
 * Core's own Magento\Store\Block\Switcher is website-scoped (it reads
 * $this->_storeManager->getWebsite()->getStores()); this is a deliberate departure, not an
 * oversight.
 */
class StoreListProvider
{
    private StoreManagerInterface $storeManager;

    private ScopeConfigInterface $scopeConfig;

    private SwitcherUrlProvider $switcherUrlProvider;

    /**
     * Memoised per variant, because four blocks ask for the same list in one render — the desktop
     * switcher twice (its own gate and its loop), the drawer three times, the sprite once, the
     * script tag once. Without this, building the desktop list would call
     * SwitcherUrlProvider::getTargetStoreRedirectUrl() — which base64-encodes the current URL per
     * store — twice for every store on every page.
     *
     * @var array<string, StoreOption[]>
     */
    private array $optionsCache = [];

    public function __construct(
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        SwitcherUrlProvider $switcherUrlProvider
    ) {
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->switcherUrlProvider = $switcherUrlProvider;
    }

    /**
     * @param bool $withRedirectUrls When true, every option carries a server-built redirect URL
     *                               that encodes the *current* request. That makes the result
     *                               request-specific, so only a caller that is never block-cached
     *                               may ask for it.
     * @return StoreOption[]
     */
    public function getOptions(bool $withRedirectUrls): array
    {
        $variant = $withRedirectUrls ? 'with_urls' : 'plain';

        if (isset($this->optionsCache[$variant])) {
            return $this->optionsCache[$variant];
        }

        $options = [];

        foreach ($this->storeManager->getStores() as $store) {
            if (!$store instanceof Store || !$store->isActive()) {
                continue;
            }

            $options[] = new StoreOption(
                (int) $store->getId(),
                (string) $store->getCode(),
                (string) $store->getName(),
                $this->getLocaleCode($store),
                $store->getBaseUrl(UrlInterface::URL_TYPE_LINK),
                $withRedirectUrls ? $this->switcherUrlProvider->getTargetStoreRedirectUrl($store) : null
            );
        }

        usort(
            $options,
            static fn (StoreOption $a, StoreOption $b): int => strcmp($a->getName(), $b->getName())
        );

        $this->optionsCache[$variant] = $options;

        return $options;
    }

    public function getCurrentStoreOption(): ?StoreOption
    {
        $store = $this->storeManager->getStore();

        if (!$store instanceof Store) {
            return null;
        }

        return new StoreOption(
            (int) $store->getId(),
            (string) $store->getCode(),
            (string) $store->getName(),
            $this->getLocaleCode($store),
            $store->getBaseUrl(UrlInterface::URL_TYPE_LINK)
        );
    }

    private function getLocaleCode(Store $store): string
    {
        return (string) $this->scopeConfig->getValue(
            DirectoryHelper::XML_PATH_DEFAULT_LOCALE,
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        );
    }
}
