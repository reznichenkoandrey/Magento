<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\Plugin;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\StoreClosure\Model\ClosureState;

/**
 * Refuses to place an order into a closed store.
 *
 * The redirect observer covers the storefront, and a storefront is not the only way into
 * `placeOrder`. Every other route converges here:
 *
 * - `Magento\Checkout\Model\PaymentInformationManagement::savePaymentInformationAndPlaceOrder()`
 *   ends in `$this->cartManagement->placeOrder($cartId)`, so the Hyvä checkout goes through it.
 * - `Magento\Quote\Model\GuestCart\GuestCartManagement::placeOrder()` unmasks the cart id and
 *   calls `$this->quoteManagement->placeOrder(...)`, a `CartManagementInterface` — so the guest
 *   REST endpoint arrives here too, already unmasked.
 * - `Magento\QuoteGraphQl\Model\Cart\PlaceOrder::execute()` calls
 *   `$this->cartManagement->placeOrder($cartId, $paymentMethod)`.
 *
 * One interface, four doors. That is why the closure is enforced here rather than in a controller.
 */
class BlockPlaceOrder
{
    private ClosureState $closureState;

    private CartRepositoryInterface $cartRepository;

    private StoreManagerInterface $storeManager;

    public function __construct(
        ClosureState $closureState,
        CartRepositoryInterface $cartRepository,
        StoreManagerInterface $storeManager
    ) {
        $this->closureState = $closureState;
        $this->cartRepository = $cartRepository;
        $this->storeManager = $storeManager;
    }

    /**
     * `before`, not `around` or `after`.
     *
     * `after` is too late — by then the order row, the invoice and any payment authorisation
     * already exist. `around` would work but hands this class the power to skip core entirely for
     * a check that only ever needs to veto, and a forgotten `$proceed()` in an `around` is the
     * quietest way to break checkout there is. `before` can only refuse.
     *
     * @param int|string $cartId
     * @return array{0: int|string, 1: PaymentInterface|null}
     * @throws CouldNotSaveException
     */
    public function beforePlaceOrder(
        CartManagementInterface $subject,
        $cartId,
        ?PaymentInterface $paymentMethod = null
    ): array {
        if ($this->closureState->isClosed($this->resolveStoreId($cartId))) {
            // CouldNotSaveException, because that is the exception placeOrder already documents
            // and every caller — REST, GraphQL and the checkout controller — already renders.
            throw new CouldNotSaveException(
                __('This store is not accepting orders at the moment.')
            );
        }

        return [$cartId, $paymentMethod];
    }

    /**
     * The quote's own store, not the current one.
     *
     * An API client picks its store scope itself — a URL segment for REST, a header for GraphQL —
     * so a cart created against the closed store and submitted under another store's scope would
     * otherwise slip past. The quote is the only party to this call that knows which store the
     * order actually belongs to.
     *
     * @param int|string $cartId
     */
    private function resolveStoreId($cartId): ?int
    {
        try {
            return (int) $this->cartRepository->get((int) $cartId)->getStoreId();
        } catch (NoSuchEntityException $e) {
            // No quote means core is about to fail anyway; fall back to the current store so the
            // closure is still enforced rather than skipped on the way to that failure.
            try {
                return (int) $this->storeManager->getStore()->getId();
            } catch (NoSuchEntityException $inner) {
                return null;
            }
        }
    }
}
