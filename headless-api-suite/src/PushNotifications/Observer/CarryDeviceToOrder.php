<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Scr1be\PushNotifications\Model\OrderNotifier;
use Scr1be\PushNotifications\Model\ResourceModel\DeviceRegistry;

/**
 * Copies the cart's device onto the order, and claims the device for the customer.
 *
 * Bound to `sales_model_service_quote_submit_before`, which
 * `Magento\Quote\Model\QuoteManagement::submitQuote()` dispatches with `['order' => $order, 'quote'
 * => $quote]` after the order object has been assembled and before `orderManagement->place()` saves
 * it. Setting the column here means it is written by the insert that creates the order, rather than
 * by a second save on the checkout's critical path.
 *
 * Globally scoped, not graphql-only, unlike the attribution observer in the sibling module. The
 * difference is where the data comes from: attribution is captured by a GraphQL plugin and exists
 * only in that area, while the device hash is on the quote row and is therefore just as present when
 * the same cart is submitted through REST or by a payment callback replaying the quote.
 */
class CarryDeviceToOrder implements ObserverInterface
{
    /**
     * @param DeviceRegistry $registry
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly DeviceRegistry $registry,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getData('order');
            $quote = $observer->getEvent()->getData('quote');

            if (!$order instanceof OrderInterface
                || !$order instanceof DataObject
                || !$quote instanceof DataObject
            ) {
                return;
            }

            $hash = (string)$quote->getData(OrderNotifier::FIELD_DEVICE_TOKEN_HASH);
            if ($hash === '') {
                return;
            }

            $order->setData(OrderNotifier::FIELD_DEVICE_TOKEN_HASH, $hash);

            // A guest who registered a device before signing up, and then created an account during
            // checkout, has an unclaimed device row and an order with a customer id. Claiming it here
            // is what lets the *next* order — placed on the web, say — still reach their phone.
            $this->registry->claim($hash, (int)$order->getCustomerId());
        } catch (\Throwable $e) {
            // A notification is a courtesy. It does not get to fail a checkout.
            $this->logger->error(
                'Scr1be_PushNotifications: could not carry the device token onto the order: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
