<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Module settings.
 */
class Config
{
    private const XML_PATH_ENABLED = 'scr1be_push/general/enabled';
    private const XML_PATH_SERVICE_ACCOUNT = 'scr1be_push/fcm/service_account';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The service-account JSON, encrypted at rest by its backend model.
     *
     * Global scope, not per store: a Firebase project belongs to an app, and an app is not per store
     * view. Splitting it by scope would invite a configuration where two store views push through
     * two projects and one app receives from neither.
     *
     * @return string
     */
    public function getServiceAccountKey(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_SERVICE_ACCOUNT);
    }
}
