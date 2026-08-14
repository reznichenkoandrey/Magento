<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Store-scoped settings for the guard.
 *
 * Every read is scoped to the store the *quote* belongs to rather than the store the request
 * happens to be resolved into. REST and GraphQL checkouts can carry a Store header that differs
 * from the default scope, and the decline copy is customer-facing wording that has to follow the
 * store view the shopper is actually checking out in.
 */
class Config
{
    private const XML_PATH_ENABLED = 'scr1be_fraud_guard/general/enabled';
    private const XML_PATH_DECLINE_MESSAGE = 'scr1be_fraud_guard/general/decline_message';
    private const XML_PATH_LOG_ATTEMPTS = 'scr1be_fraud_guard/general/log_attempts';

    /**
     * Used when an admin saves an empty decline message. A blank decline would surface as an
     * empty error bubble, which is a far louder signal to an attacker than any wording.
     */
    public const FALLBACK_DECLINE_MESSAGE =
        'Your payment was declined by the issuing bank. Please contact your card issuer or try a different payment method.';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getDeclineMessage(?int $storeId = null): string
    {
        $message = trim(
            (string) $this->scopeConfig->getValue(
                self::XML_PATH_DECLINE_MESSAGE,
                ScopeInterface::SCOPE_STORE,
                $storeId
            )
        );

        return $message !== '' ? $message : self::FALLBACK_DECLINE_MESSAGE;
    }

    public function isAttemptLoggingEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LOG_ATTEMPTS, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
