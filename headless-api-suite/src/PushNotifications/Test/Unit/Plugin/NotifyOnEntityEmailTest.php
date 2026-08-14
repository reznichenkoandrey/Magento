<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Test\Unit\Plugin;

use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\PushNotifications\Model\MessageComposer;
use Scr1be\PushNotifications\Model\OrderNotifier;
use Scr1be\PushNotifications\Plugin\NotifyOnEntityEmail;

/**
 * The plugin's whole job is deciding *whether* to push, and the decision is the thing that is easy to
 * get subtly wrong.
 */
class NotifyOnEntityEmailTest extends TestCase
{
    private OrderNotifier&MockObject $notifier;

    protected function setUp(): void
    {
        $this->notifier = $this->createMock(OrderNotifier::class);
    }

    public function testPushesWhenTheCustomerIsBeingEmailed(): void
    {
        $order = $this->order(sendEmail: true);
        $this->notifier->expects($this->once())
            ->method('notify')
            ->with($order, 'Order 000000055 confirmed', $this->anything(), ['type' => MessageComposer::TYPE_ORDER]);

        $this->plugin()->afterSend($this->createMock(OrderSender::class), true, $order);
    }

    /**
     * An admin who unticks "Notify Customer", or a store with the order email switched off, must not
     * produce a push either.
     */
    public function testDoesNotPushWhenTheEmailWasSuppressed(): void
    {
        $this->notifier->expects($this->never())->method('notify');

        $this->plugin()->afterSend($this->createMock(OrderSender::class), false, $this->order(sendEmail: false));
    }

    /**
     * The regression this plugin is shaped around. With `sales_email/general/async_sending` on,
     * `send()` returns false and the mail is queued for cron — so a plugin keyed on the return value
     * would stop pushing the moment a store enabled async sending.
     */
    public function testPushesEvenWhenTheSenderReturnedFalseBecauseSendingIsAsynchronous(): void
    {
        $this->notifier->expects($this->once())->method('notify');

        $this->plugin()->afterSend($this->createMock(OrderSender::class), false, $this->order(sendEmail: true));
    }

    /**
     * An invoice is not an order; the notification is about the order behind it.
     */
    public function testResolvesTheOrderBehindAChildEntity(): void
    {
        $order = $this->order(sendEmail: true);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getData')->willReturnCallback(
            static fn ($key = '', $index = null) => $key === 'send_email' ? true : null
        );
        $invoice->method('getOrder')->willReturn($order);

        $this->notifier->expects($this->once())
            ->method('notify')
            ->with($order, 'Invoice for order 000000055', $this->anything(), $this->anything());

        $plugin = new NotifyOnEntityEmail(
            $this->notifier,
            new MessageComposer(),
            MessageComposer::TYPE_INVOICE
        );
        $plugin->afterSend($this->createMock(InvoiceSender::class), true, $invoice);
    }

    /**
     * The return value must come back untouched: a plugin on a sender is a bystander.
     */
    public function testReturnsTheSendersResultUnchanged(): void
    {
        $this->assertTrue(
            $this->plugin()->afterSend($this->createMock(OrderSender::class), true, $this->order(sendEmail: true))
        );
        $this->assertFalse(
            $this->plugin()->afterSend($this->createMock(OrderSender::class), false, $this->order(sendEmail: false))
        );
    }

    public function testIgnoresAnEntityThatIsNotADataObject(): void
    {
        $this->notifier->expects($this->never())->method('notify');

        $this->assertTrue($this->plugin()->afterSend($this->createMock(OrderSender::class), true, new \stdClass()));
    }

    private function plugin(): NotifyOnEntityEmail
    {
        return new NotifyOnEntityEmail($this->notifier, new MessageComposer(), MessageComposer::TYPE_ORDER);
    }

    private function order(bool $sendEmail): Order&MockObject
    {
        $state = new DataObject(['send_email' => $sendEmail]);

        $order = $this->createMock(Order::class);
        $order->method('getData')->willReturnCallback(
            static fn ($key = '', $index = null) => $state->getData($key)
        );
        $order->method('getIncrementId')->willReturn('000000055');

        return $order;
    }
}
