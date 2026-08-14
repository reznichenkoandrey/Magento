<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Every knob, read once and already sanitised.
 *
 * The point of funnelling `scopeConfig` through one class is that the callers downstream — a cron
 * job, a controller, five product sources — never see a raw config string. A window of "0 days" or a
 * negative TTL is corrected here, so no query has to defend itself against a typo in the admin.
 */
class Config
{
    private const XML_PATH_ENABLED = 'scr1be_slider/general/enabled';
    private const XML_PATH_BESTSELLERS_WINDOW = 'scr1be_slider/sources/bestsellers_window_days';
    private const XML_PATH_MOST_VIEWED_WINDOW = 'scr1be_slider/sources/most_viewed_window_days';
    private const XML_PATH_DEALS_CUSTOMER_GROUP = 'scr1be_slider/sources/deals_customer_group';
    private const XML_PATH_INDEX_WINDOW = 'scr1be_slider/purchase_index/window_days';
    private const XML_PATH_PROOF_ENABLED = 'scr1be_slider/social_proof/enabled';
    private const XML_PATH_PROOF_WINDOW_HOURS = 'scr1be_slider/social_proof/window_hours';
    private const XML_PATH_PROOF_SHOW_NAME = 'scr1be_slider/social_proof/show_name';
    private const XML_PATH_PROOF_SHOW_CITY = 'scr1be_slider/social_proof/show_city';
    private const XML_PATH_PROOF_TTL = 'scr1be_slider/social_proof/endpoint_ttl';

    /**
     * A window is a range of whole days; a slider ranked over "the last 0 days" would silently show
     * nothing, which is the failure mode hardest to notice on a live page.
     */
    private const MIN_WINDOW_DAYS = 1;
    private const MAX_WINDOW_DAYS = 3650;

    private const MIN_WINDOW_HOURS = 1;
    private const MAX_WINDOW_HOURS = 8760;

    /** A day is already far longer than any social-proof line should survive in a shared cache. */
    private const MAX_TTL = 86400;

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_PATH_ENABLED, $storeId);
    }

    public function getBestsellersWindowDays(?int $storeId = null): int
    {
        return $this->days(self::XML_PATH_BESTSELLERS_WINDOW, $storeId);
    }

    public function getMostViewedWindowDays(?int $storeId = null): int
    {
        return $this->days(self::XML_PATH_MOST_VIEWED_WINDOW, $storeId);
    }

    /**
     * Which customer group's price index decides that a product is discounted.
     *
     * Deliberately a single group rather than the visitor's own: the slider is rendered inside a
     * block cache shared by every visitor, so a per-group answer would be a cache entry that is
     * right for whoever warmed it and wrong for everybody after.
     */
    public function getDealsCustomerGroupId(?int $storeId = null): int
    {
        return max(0, (int) $this->scopeConfig->getValue(
            self::XML_PATH_DEALS_CUSTOMER_GROUP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getPurchaseIndexWindowDays(?int $storeId = null): int
    {
        return $this->days(self::XML_PATH_INDEX_WINDOW, $storeId);
    }

    public function isSocialProofEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_PATH_PROOF_ENABLED, $storeId);
    }

    public function getSocialProofWindowHours(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_PROOF_WINDOW_HOURS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return max(self::MIN_WINDOW_HOURS, min(self::MAX_WINDOW_HOURS, $value));
    }

    public function isBuyerNameShown(?int $storeId = null): bool
    {
        return $this->flag(self::XML_PATH_PROOF_SHOW_NAME, $storeId);
    }

    public function isBuyerCityShown(?int $storeId = null): bool
    {
        return $this->flag(self::XML_PATH_PROOF_SHOW_CITY, $storeId);
    }

    public function getProofEndpointTtl(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_PROOF_TTL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return max(0, min(self::MAX_TTL, $value));
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function days(string $path, ?int $storeId): int
    {
        $value = (int) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);

        return max(self::MIN_WINDOW_DAYS, min(self::MAX_WINDOW_DAYS, $value));
    }
}
