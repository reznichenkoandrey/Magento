<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Settings, read at default scope, with the master switch folded into both readers.
 *
 * **Default scope, not store scope, is a decision.** A REST call arrives at `/rest/<store>/…`, and
 * a terminal that is not told otherwise sends `/rest/all/…`, which resolves to the admin store.
 * Store-scoped switches would therefore be settings an integrator could fill in on a store view,
 * see saved, and never see take effect — the worst kind of configuration. These are properties of
 * the back-office bridge as a whole, and they are scoped like it.
 */
class Config
{
    private const XML_PATH_ENABLED = 'scr1be_pos_bridge/general/enabled';
    private const XML_PATH_IMPERSONATION_ENABLED = 'scr1be_pos_bridge/general/impersonation_enabled';
    private const XML_PATH_RESULT_LIMIT = 'scr1be_pos_bridge/search/result_limit';

    /**
     * Applied to whatever the configuration holds. A cap is the only thing standing between a
     * three-letter query and a response carrying the customer table, so it is not something a typo
     * in `core_config_data` gets to switch off.
     */
    public const MIN_RESULT_LIMIT = 1;
    public const MAX_RESULT_LIMIT = 100;
    public const DEFAULT_RESULT_LIMIT = 20;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * Impersonation has its own switch because the two endpoints are not equally dangerous. A shop
     * that wants the lookup on a terminal but wants act-as-customer to stay a desk operation turns
     * this one off and keeps the other.
     */
    public function isImpersonationEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(self::XML_PATH_IMPERSONATION_ENABLED);
    }

    public function getResultLimit(): int
    {
        $configured = (int) $this->scopeConfig->getValue(self::XML_PATH_RESULT_LIMIT);
        if ($configured <= 0) {
            $configured = self::DEFAULT_RESULT_LIMIT;
        }

        return max(self::MIN_RESULT_LIMIT, min(self::MAX_RESULT_LIMIT, $configured));
    }
}
