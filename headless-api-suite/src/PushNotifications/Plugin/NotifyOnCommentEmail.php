<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Plugin;

use Magento\Sales\Api\Data\OrderInterface;
use Scr1be\PushNotifications\Model\MessageComposer;
use Scr1be\PushNotifications\Model\OrderNotifier;

/**
 * Pushes alongside the four "somebody commented" sales emails.
 *
 * Targets: `OrderCommentSender`, `InvoiceCommentSender`, `ShipmentCommentSender`,
 * `CreditmemoCommentSender`. Their shape is `send($entity, $notify = true, $comment = ''): bool`, and
 * the decision is read from `$notify` rather than from the return value — because
 * `Magento\Sales\Model\Order\Email\NotifySender::checkAndSend()` returns `true` even when `$notify`
 * is false. In that case it has sent a *copy* to the store's own address and nothing to the customer,
 * so a plugin keyed on the return value would push to the shopper's phone about a message they were
 * deliberately not sent.
 *
 * That is the whole reason there are two plugin classes rather than one: the two sender shapes
 * disagree about where the "notify the customer" decision lives.
 *
 * @see \Magento\Sales\Model\Order\Email\NotifySender::checkAndSend()
 */
class NotifyOnCommentEmail
{
    /**
     * @param OrderNotifier $notifier
     * @param MessageComposer $composer
     * @param string $notificationType One of MessageComposer's TYPE_*_COMMENT constants.
     */
    public function __construct(
        private readonly OrderNotifier $notifier,
        private readonly MessageComposer $composer,
        private readonly string $notificationType = MessageComposer::TYPE_ORDER_COMMENT
    ) {
    }

    /**
     * @param object $subject
     * @param bool $result
     * @param object $entity Order, Invoice, Shipment or Creditmemo.
     * @param bool $notify
     * @param string $comment
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSend(object $subject, $result, $entity, $notify = true, $comment = '')
    {
        if (!$notify || !$result) {
            return $result;
        }

        $order = $this->resolveOrder($entity);
        if ($order === null) {
            return $result;
        }

        $message = $this->composer->compose($this->notificationType, $order, (string)$comment);
        $this->notifier->notify($order, $message['title'], $message['body'], ['type' => $this->notificationType]);

        return $result;
    }

    /**
     * @param object $entity
     * @return OrderInterface|null
     */
    private function resolveOrder(object $entity): ?OrderInterface
    {
        if ($entity instanceof OrderInterface) {
            return $entity;
        }

        $order = method_exists($entity, 'getOrder') ? $entity->getOrder() : null;

        return $order instanceof OrderInterface ? $order : null;
    }
}
