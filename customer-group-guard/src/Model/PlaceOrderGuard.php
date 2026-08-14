<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Model;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\CartInterface;
use Psr\Log\LoggerInterface;

/**
 * The hard path: a cart priced for a group the customer is no longer in does not become an order.
 *
 * This ladder compares two things the soft path never touches — the *quote's* group column and
 * the customer record — so it holds whether or not the browser ever ran a line of this module's
 * JavaScript, whether or not a CDN kept the cookie, and whether or not the customer-data section
 * had been refreshed yet. That independence is the reason there are two paths: the soft one is
 * eventually consistent by construction, and money is not something to be eventually consistent
 * about.
 *
 * The failure is recoverable on purpose. Refusing the order leaves the cart intact; reloading it
 * lets core reassign the group onto the quote, and signing out and back in — which the soft path
 * asks for anyway — rebuilds it from scratch. A guard that emptied the cart to "fix" the state
 * would be destroying the one artefact the shopper cares about.
 */
class PlaceOrderGuard
{
    /**
     * Read through DataObject rather than through a getter: CartInterface does not publish the
     * quote's own customer_group_id, and the group on the quote row is precisely the value that
     * has to be judged. Quote::getCustomer() would be no help — it answers from the customer
     * repository, so it returns the current group and would be comparing the database with
     * itself.
     */
    private const QUOTE_GROUP_FIELD = 'customer_group_id';

    public function __construct(
        private readonly Config $config,
        private readonly GroupResolver $groupResolver,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws LocalizedException when the cart's group no longer matches the customer's.
     */
    public function assertGroupIsCurrent(CartInterface $quote): void
    {
        $storeId = $quote->getStoreId() === null ? null : (int) $quote->getStoreId();
        if (!$this->config->isPlaceOrderBlockEnabled($storeId)) {
            return;
        }

        $customerId = $this->resolveCustomerId($quote);
        if ($customerId === 0) {
            // A guest checkout carries the NOT LOGGED IN group and no customer record to
            // contradict it. There is nothing here that an admin can change underneath them.
            return;
        }

        $quoteGroupId = $this->resolveQuoteGroupId($quote);
        if ($quoteGroupId === null) {
            // No group on the quote is not a mismatch, it is an unanswerable question. Core
            // assigns one on its own path to the order.
            return;
        }

        $storedGroupId = $this->groupResolver->resolveStoredGroupId($customerId);
        if ($storedGroupId === null || $storedGroupId === $quoteGroupId) {
            return;
        }

        // One line, in system.log rather than a file of its own: a refused checkout is rare and
        // support-visible, and the first question asked about one is always "did we do that".
        $this->logger->warning(
            'scr1be_customer_group_guard: refused a place-order from a stale cart',
            [
                'quote_id' => $quote->getId(),
                'customer_id' => $customerId,
                'quote_group_id' => $quoteGroupId,
                'customer_group_id' => $storedGroupId,
            ]
        );

        $message = __('Your account changed while you were shopping. Please refresh your cart and try again.');

        throw new LocalizedException($message);
    }

    private function resolveCustomerId(CartInterface $quote): int
    {
        $customer = $quote->getCustomer();

        return $customer === null ? 0 : (int) $customer->getId();
    }

    private function resolveQuoteGroupId(CartInterface $quote): ?int
    {
        if (!$quote instanceof DataObject) {
            return null;
        }

        $groupId = $quote->getData(self::QUOTE_GROUP_FIELD);

        return $groupId === null || $groupId === '' ? null : (int) $groupId;
    }
}
