<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The one flag everything else in this module reads.
 *
 * Store scope, not website: a closure is usually one market going quiet — a country whose
 * warehouse has stopped shipping, a language store being retired — while its siblings on the same
 * website keep selling. Everything that enforces the closure therefore has to ask about a
 * *specific* store, which is why every method here takes a store id and the current-store
 * convenience is a separate call.
 */
class ClosureState
{
    public const XML_PATH_ENABLED = 'scr1be_store_closure/general/enabled';
    public const XML_PATH_HEADLINE = 'scr1be_store_closure/general/headline';
    public const XML_PATH_MESSAGE = 'scr1be_store_closure/general/message';
    public const XML_PATH_BANNER = 'scr1be_store_closure/general/banner';
    public const XML_PATH_HIDE_PRICES = 'scr1be_store_closure/general/hide_prices';

    private ScopeConfigInterface $scopeConfig;

    private StoreManagerInterface $storeManager;

    public function __construct(ScopeConfigInterface $scopeConfig, StoreManagerInterface $storeManager)
    {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    public function isClosed(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * False when the store cannot be resolved at all, because refusing to serve a page is a worse
     * failure mode than serving one during a misconfiguration.
     */
    public function isCurrentStoreClosed(): bool
    {
        try {
            return $this->isClosed((int) $this->storeManager->getStore()->getId());
        } catch (NoSuchEntityException $e) {
            return false;
        }
    }

    /**
     * Prices are hidden separately from the closure itself: a store that has stopped taking orders
     * but wants to keep its catalogue browsable as a lookbook is a real request, and one that a
     * single boolean cannot express.
     */
    public function shouldHidePrices(?int $storeId = null): bool
    {
        return $this->isClosed($storeId)
            && $this->scopeConfig->isSetFlag(self::XML_PATH_HIDE_PRICES, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getHeadline(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_HEADLINE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getMessage(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_MESSAGE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Media-relative path of the uploaded banner, as the config backend model stored it.
     */
    public function getBannerFile(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_BANNER, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
