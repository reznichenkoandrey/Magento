<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Store-scoped settings, with the master switch folded into both readers.
 *
 * Folding it in here rather than checking it at each call site is the point of this class: the
 * soft path and the hard path are read from four different places, and a master switch that has
 * to be remembered at each of them is a master switch that will eventually be forgotten at one.
 *
 * Every read is store-scoped. A multi-store installation routinely runs one storefront for
 * wholesale and one for retail, and only one of them has a group ladder worth enforcing.
 */
class Config
{
    private const XML_PATH_ENABLED = 'scr1be_customer_group_guard/general/enabled';
    private const XML_PATH_FORCE_LOGOUT = 'scr1be_customer_group_guard/general/force_logout';
    private const XML_PATH_BLOCK_PLACE_ORDER = 'scr1be_customer_group_guard/general/block_place_order';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->isFlagSet(self::XML_PATH_ENABLED, $storeId);
    }

    /**
     * The soft path: the customer-data section and the Alpine component that acts on it.
     */
    public function isForceLogoutEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->isFlagSet(self::XML_PATH_FORCE_LOGOUT, $storeId);
    }

    /**
     * The hard path: the place-order guard.
     */
    public function isPlaceOrderBlockEnabled(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->isFlagSet(self::XML_PATH_BLOCK_PLACE_ORDER, $storeId);
    }

    private function isFlagSet(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
