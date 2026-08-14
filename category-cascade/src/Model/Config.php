<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Store-scoped settings.
 *
 * The cascade settings are read in the scope of the *category being saved*, not the scope the
 * request happens to resolve into. A category saved in a store view is a store-scoped decision,
 * and a merchant who runs one store view under different rules has to be able to say so.
 *
 * The product-count setting is deliberately default-scope only: it changes how an admin grid
 * builds a number, and an admin screen has no store scope of its own to inherit from.
 */
class Config
{
    private const XML_PATH_ENABLED = 'scr1be_category_cascade/general/enabled';
    private const XML_PATH_CONFIRM_PROMPT = 'scr1be_category_cascade/general/confirm_prompt';
    private const XML_PATH_LOG_CASCADES = 'scr1be_category_cascade/general/log_cascades';
    private const XML_PATH_USE_INDEX_COUNT = 'scr1be_category_cascade/product_count/use_index';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isCascadeEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isConfirmPromptEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIRM_PROMPT, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isCascadeLoggingEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LOG_CASCADES, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isIndexedProductCountEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_USE_INDEX_COUNT);
    }
}
