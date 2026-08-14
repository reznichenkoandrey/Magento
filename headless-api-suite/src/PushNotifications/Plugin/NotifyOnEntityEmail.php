<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Plugin;

use Magento\Framework\DataObject;
use Magento\Sales\Api\Data\OrderInterface;
use Scr1be\PushNotifications\Model\MessageComposer;
use Scr1be\PushNotifications\Model\OrderNotifier;

/**
 * Pushes alongside the four "an X was created" sales emails.
 *
 * Targets: `OrderSender`, `InvoiceSender`, `ShipmentSender`, `CreditmemoSender`. All four have the
 * shape `send($entity, $forceSyncMode = false): bool`, and all four are wired to *this* class as a
 * virtual type carrying the notification type — eight near-identical plugin classes would be eight
 * places to fix the next time the notification copy changes.
 *
 * **Why `after` and not an observer.** The point of hanging off the sender is to inherit "Notify
 * Customer" for free. An admin who unticks that box, or a store with the order email disabled, must
 * not get a push either — and the only component that knows the outcome of that decision is the
 * sender.
 *
 * **Why the flag on the entity rather than the return value.** `send()` returns `true` only when the
 * mail went out synchronously. With `sales_email/general/async_sending` on — the recommended setting
 * on any busy store — it returns `false` and the mail is queued for cron, so a plugin keyed on the
 * return value would silently stop pushing the moment async sending was enabled. All four senders
 * begin with `$entity->setSendEmail($this->identityContainer->isEnabled())` before they branch on
 * async mode, so `send_email` is the decision itself rather than a report of one delivery attempt.
 *
 * @see \Magento\Sales\Model\Order\Email\Sender\OrderSender::send()
 */
class NotifyOnEntityEmail
{
    /**
     * @param OrderNotifier $notifier
     * @param MessageComposer $composer
     * @param string $notificationType One of MessageComposer's TYPE_* constants.
     */
    public function __construct(
        private readonly OrderNotifier $notifier,
        private readonly MessageComposer $composer,
        private readonly string $notificationType = MessageComposer::TYPE_ORDER
    ) {
    }

    /**
     * @param object $subject
     * @param bool $result
     * @param object $entity Order, Invoice, Shipment or Creditmemo.
     * @param bool $forceSyncMode
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSend(object $subject, $result, $entity, $forceSyncMode = false)
    {
        if (!$entity instanceof DataObject || !$entity->getData('send_email')) {
            return $result;
        }

        $order = $this->resolveOrder($entity);
        if ($order === null) {
            return $result;
        }

        $message = $this->composer->compose($this->notificationType, $order);
        $this->notifier->notify($order, $message['title'], $message['body'], ['type' => $this->notificationType]);

        return $result;
    }

    /**
     * The order behind whichever entity was sent.
     *
     * `send_email` is a column on all four tables but is not on any of the four service contracts, so
     * it is read with `getData()` rather than with a magic getter a static analyser cannot see.
     *
     * @param DataObject $entity
     * @return OrderInterface|null
     */
    private function resolveOrder(DataObject $entity): ?OrderInterface
    {
        if ($entity instanceof OrderInterface) {
            return $entity;
        }

        $order = method_exists($entity, 'getOrder') ? $entity->getOrder() : null;

        return $order instanceof OrderInterface ? $order : null;
    }
}
