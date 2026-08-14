<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Plugin\Quote;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Scr1be\FraudGuard\Model\PlaceOrderGuard;

/**
 * The primary choke point.
 *
 * `before` rather than `around`: the guard either throws or does nothing, so there is no reason
 * to hold a closure over the whole of core's place-order. It also keeps the plugin honest — a
 * `before` plugin physically cannot swallow a core exception or alter the returned order id.
 *
 * The hook sits on CartManagementInterface, not on the Quote model or on a payment method,
 * because every storefront path converges here: the Luma/Hyva checkout REST call
 * (savePaymentInformationAndPlaceOrder -> placeOrder), a direct REST placeOrder, and the GraphQL
 * placeOrder mutation. One plugin therefore covers every gateway that will ever be installed,
 * including ones written after this module.
 *
 * What it does *not* cover, and this is worth stating plainly: the payment method has already
 * been assigned to the quote by the time the REST wrapper calls placeOrder. Assignment is a local
 * write — no authorization, no tokenization, no money movement — so no gateway is contacted and
 * the attacker learns nothing about the card. Intercepting earlier would mean one plugin per
 * gateway, which is exactly the maintenance trap this module avoids.
 */
class DeclineFlaggedPlaceOrder
{
    public function __construct(
        private readonly PlaceOrderGuard $guard,
        private readonly CartRepositoryInterface $cartRepository
    ) {
    }

    /**
     * @param int|string $cartId
     * @throws \Magento\Payment\Gateway\Command\CommandException
     */
    public function beforePlaceOrder(
        CartManagementInterface $subject,
        $cartId,
        ?PaymentInterface $paymentMethod = null
    ): void {
        $quote = $this->loadQuote((int) $cartId);
        if ($quote === null) {
            return;
        }

        $this->guard->assertNotFlagged($quote);
    }

    /**
     * Core's own placeOrder() opens with getActive($cartId), and QuoteRepository keeps an
     * identity map, so this load warms the cache core is about to read: the guard adds no query
     * to a checkout. get() rather than getActive() because cart *state* is core's judgement to
     * make — the guard has no business turning an inactive-cart error into a fraud decline.
     */
    private function loadQuote(int $cartId): ?CartInterface
    {
        try {
            return $this->cartRepository->get($cartId);
        } catch (NoSuchEntityException) {
            // No cart to judge. Core will raise its own error a moment later.
            return null;
        }
    }
}
