<?php
declare(strict_types=1);

namespace Scr1be\GuestRegistration\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads the module's settings.
 */
class Config
{
    private const XML_PATH_ENABLED = 'scr1be_guest_registration/general/enabled';
    private const XML_PATH_LINK_EXISTING = 'scr1be_guest_registration/general/link_existing';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * Whether guest orders in this store view should produce accounts.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Whether an order whose email already has an account should be attached to it.
     *
     * Separate from the main switch because it is a different decision with a different risk: the
     * create path only ever touches data the shopper just typed, while the link path attaches an
     * order to a pre-existing account on the strength of an email address alone. A merchant who
     * wants auto-registration but not that inference can have exactly that.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function shouldLinkExisting(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LINK_EXISTING, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
