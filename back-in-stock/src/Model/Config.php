<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Every configuration read in the module, in one place, with the clamping done here rather than at
 * each call site.
 *
 * The popup settings are store-scoped because a popup is a piece of storefront copy; the push
 * settings are website-scoped because a Firebase project is an installation-level credential and a
 * device token is registered against a website.
 */
class Config
{
    public const XML_PATH_POPUP_ENABLED = 'scr1be_back_in_stock/popup/enabled';
    public const XML_PATH_POPUP_MAX_ITEMS = 'scr1be_back_in_stock/popup/max_items';
    public const XML_PATH_LOW_STOCK_THRESHOLD = 'scr1be_back_in_stock/popup/low_stock_threshold';
    public const XML_PATH_PUSH_ENABLED = 'scr1be_back_in_stock/push/enabled';
    public const XML_PATH_PUSH_PROJECT_ID = 'scr1be_back_in_stock/push/fcm_project_id';
    public const XML_PATH_PUSH_SERVICE_ACCOUNT = 'scr1be_back_in_stock/push/fcm_service_account';
    public const XML_PATH_PUSH_TITLE = 'scr1be_back_in_stock/push/message_title';

    /**
     * The popup is a modal over whatever the customer came to do. Six cards is roughly a phone
     * screen; past that it is a page, and a page is what the account section is for.
     */
    private const DEFAULT_MAX_ITEMS = 6;
    private const MIN_MAX_ITEMS = 1;
    private const MAX_MAX_ITEMS = 24;

    /** A "only n left" badge that fires at 500 units is noise, so the threshold has a ceiling too. */
    private const DEFAULT_LOW_STOCK_THRESHOLD = 5;
    private const MAX_LOW_STOCK_THRESHOLD = 100;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isPopupEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_POPUP_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * How many cards the popup carries, and therefore how many rows the provider reads.
     */
    public function getMaxItems(?int $storeId = null): int
    {
        $configured = (int)$this->scopeConfig->getValue(
            self::XML_PATH_POPUP_MAX_ITEMS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($configured < self::MIN_MAX_ITEMS || $configured > self::MAX_MAX_ITEMS) {
            return self::DEFAULT_MAX_ITEMS;
        }

        return $configured;
    }

    /**
     * Salable quantity at or below which a card carries the urgency badge. Zero switches the badge
     * off entirely, which is the honest way to opt out of a claim about stock levels.
     */
    public function getLowStockThreshold(?int $storeId = null): int
    {
        $configured = (int)$this->scopeConfig->getValue(
            self::XML_PATH_LOW_STOCK_THRESHOLD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($configured < 0 || $configured > self::MAX_LOW_STOCK_THRESHOLD) {
            return self::DEFAULT_LOW_STOCK_THRESHOLD;
        }

        return $configured;
    }

    public function isPushEnabled(?int $websiteId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PUSH_ENABLED,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );
    }

    public function getPushProjectId(?int $websiteId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_PUSH_PROJECT_ID,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        ));
    }

    /**
     * The service-account JSON, decrypted.
     *
     * `Magento\Config\Model\Config\Backend\Encrypted` encrypts the value on save and decrypts it in
     * `_afterLoad()` — which runs for the admin form's config model, not for
     * `ScopeConfigInterface::getValue()`. So the caller decrypts, and core does the same thing in the
     * same shape: `Magento\NewRelicReporting\Model\Config::getInsightsInsertKey()` is
     * `$this->encryptor->decrypt($this->scopeConfig->getValue(...))` against a field declared with
     * exactly this backend model.
     */
    public function getPushServiceAccountJson(?int $websiteId = null): string
    {
        $stored = (string)$this->scopeConfig->getValue(
            self::XML_PATH_PUSH_SERVICE_ACCOUNT,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );

        if ($stored === '') {
            return '';
        }

        return (string)$this->encryptor->decrypt($stored);
    }

    /**
     * The push title. The body is the product name, which is data rather than copy.
     */
    public function getPushTitle(?int $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_PUSH_TITLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }
}
