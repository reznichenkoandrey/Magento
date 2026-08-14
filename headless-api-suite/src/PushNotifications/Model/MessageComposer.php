<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Model;

use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * The eight notifications, in one place.
 *
 * Translated with `__()` and therefore rendered in the *admin's* current locale unless the caller
 * has emulated the customer's store — which the sender plugins do not, because they run after core
 * has already stopped its own emulation. That is a real limitation and it is stated in the README
 * rather than hidden: a single-locale storefront is unaffected, and a multi-locale one wanting
 * per-store copy should localise in the app from the `type` key in the data payload, which is why
 * that key is always sent.
 */
class MessageComposer
{
    public const TYPE_ORDER = 'order_placed';
    public const TYPE_INVOICE = 'invoice_created';
    public const TYPE_SHIPMENT = 'shipment_created';
    public const TYPE_CREDITMEMO = 'creditmemo_created';
    public const TYPE_ORDER_COMMENT = 'order_comment';
    public const TYPE_INVOICE_COMMENT = 'invoice_comment';
    public const TYPE_SHIPMENT_COMMENT = 'shipment_comment';
    public const TYPE_CREDITMEMO_COMMENT = 'creditmemo_comment';

    /**
     * @param string $type
     * @param OrderInterface $order
     * @param string $comment
     * @return array{title: string, body: string}
     */
    public function compose(string $type, OrderInterface $order, string $comment = ''): array
    {
        $incrementId = (string)$order->getIncrementId();

        $title = match ($type) {
            self::TYPE_ORDER => __('Order %1 confirmed', $incrementId),
            self::TYPE_INVOICE => __('Invoice for order %1', $incrementId),
            self::TYPE_SHIPMENT => __('Order %1 is on its way', $incrementId),
            self::TYPE_CREDITMEMO => __('Refund for order %1', $incrementId),
            default => __('Update on order %1', $incrementId),
        };

        $body = match ($type) {
            self::TYPE_ORDER => __('Thank you. We have received your order.'),
            self::TYPE_INVOICE => __('Your invoice is ready.'),
            self::TYPE_SHIPMENT => __('Your parcel has been dispatched.'),
            self::TYPE_CREDITMEMO => __('Your refund has been issued.'),
            default => $this->commentBody($comment),
        };

        return [
            'title' => $this->toString($title),
            'body' => $this->toString($body),
        ];
    }

    /**
     * A comment notification carries the comment, trimmed to something a lock screen can show.
     *
     * Both iOS and Android truncate a notification body themselves, but they do it after the payload
     * has crossed the network — and an order comment can be several paragraphs of internal notes that
     * a merchant chose to send to the customer. Cutting here keeps the payload small and keeps the
     * truncation predictable.
     *
     * @param string $comment
     * @return Phrase|string
     */
    private function commentBody(string $comment)
    {
        $comment = trim(strip_tags($comment));

        if ($comment === '') {
            return __('There is an update on your order.');
        }

        return mb_strlen($comment) > 120 ? mb_substr($comment, 0, 119) . '…' : $comment;
    }

    /**
     * @param Phrase|string $value
     * @return string
     */
    private function toString($value): string
    {
        return $value instanceof Phrase ? $value->render() : (string)$value;
    }
}
