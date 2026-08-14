<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Quote\Api\Data\CartInterface;

/**
 * The decision ladder, shared by both place-order plugins.
 *
 * Everything the module promises is in the order of these five checks and in the *type* of the
 * exception thrown at the end.
 */
class PlaceOrderGuard
{
    public function __construct(
        private readonly Config $config,
        private readonly FlagResolver $flagResolver,
        private readonly GuardLog $log,
        private readonly State $appState
    ) {
    }

    /**
     * @throws CommandException when the quote belongs to a flagged customer.
     */
    public function assertNotFlagged(CartInterface $quote): void
    {
        $storeId = $quote->getStoreId() === null ? null : (int) $quote->getStoreId();

        if (!$this->config->isEnabled($storeId)) {
            return;
        }

        if ($this->isMerchantInitiated()) {
            return;
        }

        $customerId = $this->resolveCustomerId($quote);
        if ($customerId === 0) {
            // Guests are out of scope by construction — see the threat model in the README.
            return;
        }

        if (!$this->flagResolver->isFlagged($customerId)) {
            return;
        }

        $this->log->blockedAttempt($quote, $customerId);

        // CommandException, not a bare LocalizedException, is the whole point. It is the exact
        // class Magento\Payment throws when a gateway declines, so every handler downstream —
        // the REST wrapper, the GraphQL resolver, a payment module's own catch block — treats
        // this identically to a real decline. Nothing about the response says "rule engine".
        throw new CommandException(new Phrase($this->config->getDeclineMessage($storeId)));
    }

    /**
     * An admin creating an order for a flagged customer is a deliberate merchant decision —
     * usually the phone call that follows a false positive. The guard exists to stop unattended
     * card testing, so it steps aside for anything originating in the backend.
     */
    private function isMerchantInitiated(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (LocalizedException) {
            // No area resolved yet. Nothing merchant-facing reaches place-order that early, so
            // the safe reading is "not the admin" and the guard stays on.
            return false;
        }
    }

    private function resolveCustomerId(CartInterface $quote): int
    {
        $customer = $quote->getCustomer();

        return $customer === null ? 0 : (int) $customer->getId();
    }
}
