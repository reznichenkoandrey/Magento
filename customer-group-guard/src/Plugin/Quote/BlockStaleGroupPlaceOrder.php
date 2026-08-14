<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Plugin\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Scr1be\CustomerGroupGuard\Model\PlaceOrderGuard;

/**
 * The one choke point every checkout goes through.
 *
 * Whatever the storefront looks like — the Hyva checkout posting payment information, a headless
 * client calling REST directly, the GraphQL placeOrder mutation — the last common step before an
 * order exists is CartManagementInterface::placeOrder(). Hooking it once covers all of them and
 * keeps working for checkout implementations written after this module.
 *
 * The plugin holds no logic of its own: it turns a cart id into a quote and hands it to the
 * guard. Everything that could be wrong about the decision lives in PlaceOrderGuard, where it can
 * be read in one place and unit tested without a plugin harness.
 */
class BlockStaleGroupPlaceOrder
{
    public function __construct(
        private readonly PlaceOrderGuard $guard,
        private readonly CartRepositoryInterface $cartRepository
    ) {
    }

    /**
     * @param int|string $cartId
     * @throws LocalizedException
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

        $this->guard->assertGroupIsCurrent($quote);
    }

    /**
     * Core's placeOrder() opens by loading the same cart, and the quote repository keeps a
     * per-request identity map, so this load is the one core was about to do — the guard adds no
     * query to a checkout.
     *
     * get() rather than getActive(): whether a cart is still active is core's judgement, and the
     * guard has no business turning "this cart is not active" into a message about pricing.
     */
    private function loadQuote(int $cartId): ?CartInterface
    {
        try {
            return $this->cartRepository->get($cartId);
        } catch (NoSuchEntityException) {
            // Nothing to judge. Core raises its own error on the very next line.
            return null;
        }
    }
}
