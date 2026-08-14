<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Model;

use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Scr1be\PushNotifications\Api\PushTransportInterface;
use Scr1be\PushNotifications\Model\ResourceModel\DeviceRegistry;

/**
 * Turns "an order email just went out" into "the customer's phone buzzed".
 *
 * **Which devices.** The order's own device first: `sales_order.scr1be_device_token_hash`, carried
 * over from the cart, is the phone the order was placed on and the one whose owner is expecting news.
 * If the order has no device — a web order from a customer who also has the app — every live device
 * belonging to that customer is used instead. A guest order with no device reaches nobody, which is
 * correct: there is no identity to deliver to.
 *
 * **Self-healing.** A transport that reports a token as permanently dead gets the row deactivated
 * immediately, so the next order does not try it again. This is the only place the registry is
 * written outside registration, and it is why `PushResult` distinguishes a dead token from a failure.
 */
class OrderNotifier
{
    public const FIELD_DEVICE_TOKEN_HASH = 'scr1be_device_token_hash';

    /**
     * @param Config $config
     * @param DeviceRegistry $registry
     * @param PushTransportInterface $transport
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly DeviceRegistry $registry,
        private readonly PushTransportInterface $transport,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Notify every device that should hear about this order.
     *
     * Never throws: the caller is a plugin on an email sender, inside an order save.
     *
     * @param OrderInterface $order
     * @param string $title
     * @param string $body
     * @param array<string, string> $data
     * @return int How many devices were reached.
     */
    public function notify(OrderInterface $order, string $title, string $body, array $data = []): int
    {
        try {
            if (!$this->config->isEnabled((int)$order->getStoreId())) {
                return 0;
            }

            $delivered = 0;
            foreach ($this->tokensFor($order) as $token) {
                $result = $this->transport->send(
                    new PushMessage(
                        $token,
                        $title,
                        $body,
                        $data + [
                            'order_id' => (string)$order->getEntityId(),
                            'increment_id' => (string)$order->getIncrementId(),
                        ]
                    )
                );

                if ($result->delivered) {
                    $delivered++;
                } elseif ($result->tokenIsDead) {
                    $this->registry->deactivate($token, (string)$result->reason);
                }
            }

            return $delivered;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Scr1be_PushNotifications: could not notify for order '
                . (string)$order->getIncrementId() . ': ' . $e->getMessage(),
                ['exception' => $e]
            );

            return 0;
        }
    }

    /**
     * @param OrderInterface $order
     * @return string[]
     */
    private function tokensFor(OrderInterface $order): array
    {
        $hash = $order instanceof \Magento\Framework\DataObject
            ? (string)$order->getData(self::FIELD_DEVICE_TOKEN_HASH)
            : '';

        if ($hash !== '') {
            $token = $this->registry->findActiveToken($hash);
            if ($token !== null) {
                return [$token];
            }
        }

        return $this->registry->findActiveTokensForCustomer((int)$order->getCustomerId());
    }
}
