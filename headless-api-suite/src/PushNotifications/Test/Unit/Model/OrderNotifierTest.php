<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Test\Unit\Model;

use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\PushNotifications\Api\PushTransportInterface;
use Scr1be\PushNotifications\Model\Config;
use Scr1be\PushNotifications\Model\OrderNotifier;
use Scr1be\PushNotifications\Model\PushMessage;
use Scr1be\PushNotifications\Model\PushResult;
use Scr1be\PushNotifications\Model\ResourceModel\DeviceRegistry;

/**
 * Device selection and self-healing — the two behaviours that decide whether the right phone buzzes
 * and whether the registry stays honest.
 */
class OrderNotifierTest extends TestCase
{
    private Config&MockObject $config;
    private DeviceRegistry&MockObject $registry;
    private PushTransportInterface&MockObject $transport;
    private LoggerInterface&MockObject $logger;

    /**
     * @var PushMessage[]
     */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isEnabled')->willReturn(true);

        $this->registry = $this->createMock(DeviceRegistry::class);

        $this->transport = $this->createMock(PushTransportInterface::class);
        $this->transport->method('send')->willReturnCallback(
            function (PushMessage $message) {
                $this->sent[] = $message;

                return PushResult::delivered();
            }
        );

        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * The device the order was placed on wins. It is the phone whose owner is waiting.
     */
    public function testPrefersTheOrdersOwnDevice(): void
    {
        $this->registry->method('findActiveToken')->with('hash-1')->willReturn('token-1');
        $this->registry->expects($this->never())->method('findActiveTokensForCustomer');

        $delivered = $this->notifier()->notify($this->order('hash-1', 7), 'T', 'B');

        $this->assertSame(1, $delivered);
        $this->assertSame(['token-1'], array_map(static fn ($m) => $m->token, $this->sent));
    }

    /**
     * A web order from somebody who also has the app.
     */
    public function testFallsBackToEveryDeviceTheCustomerHas(): void
    {
        $this->registry->method('findActiveToken')->willReturn(null);
        $this->registry->method('findActiveTokensForCustomer')->with(7)->willReturn(['a', 'b']);

        $this->assertSame(2, $this->notifier()->notify($this->order('', 7), 'T', 'B'));
    }

    /**
     * A hash pointing at a deactivated row is not a reason to give up: the customer may have other
     * live devices.
     */
    public function testAStaleHashFallsThroughToTheCustomersDevices(): void
    {
        $this->registry->method('findActiveToken')->willReturn(null);
        $this->registry->method('findActiveTokensForCustomer')->willReturn(['a']);

        $this->assertSame(1, $this->notifier()->notify($this->order('hash-gone', 7), 'T', 'B'));
    }

    /**
     * A guest order with no device has nobody to notify, and that is the correct answer rather than
     * an error.
     */
    public function testAGuestOrderWithNoDeviceReachesNobody(): void
    {
        $this->registry->method('findActiveToken')->willReturn(null);
        $this->registry->method('findActiveTokensForCustomer')->with(0)->willReturn([]);

        $this->assertSame(0, $this->notifier()->notify($this->order('', 0), 'T', 'B'));
    }

    public function testSendsNothingWhenTheModuleIsOff(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isEnabled')->willReturn(false);
        $this->registry->expects($this->never())->method('findActiveToken');

        $this->assertSame(0, $this->notifier()->notify($this->order('hash-1', 7), 'T', 'B'));
    }

    /**
     * The self-healing half of the contract.
     */
    public function testADeadTokenIsDeactivatedImmediately(): void
    {
        $this->registry->method('findActiveToken')->willReturn('token-1');
        $this->transport = $this->createMock(PushTransportInterface::class);
        $this->transport->method('send')->willReturn(PushResult::tokenIsDead('UNREGISTERED'));
        $this->registry->expects($this->once())->method('deactivate')->with('token-1', 'UNREGISTERED');

        $this->assertSame(0, $this->notifier()->notify($this->order('hash-1', 7), 'T', 'B'));
    }

    /**
     * A transient failure must not cost the customer their subscription.
     */
    public function testATransientFailureLeavesTheRegistryAlone(): void
    {
        $this->registry->method('findActiveToken')->willReturn('token-1');
        $this->transport = $this->createMock(PushTransportInterface::class);
        $this->transport->method('send')->willReturn(PushResult::failed('HTTP 503'));
        $this->registry->expects($this->never())->method('deactivate');

        $this->assertSame(0, $this->notifier()->notify($this->order('hash-1', 7), 'T', 'B'));
    }

    /**
     * Every payload carries the order, so the app can deep-link without a second lookup.
     */
    public function testEveryMessageCarriesTheOrderIdentifiers(): void
    {
        $this->registry->method('findActiveToken')->willReturn('token-1');

        $this->notifier()->notify($this->order('hash-1', 7), 'T', 'B', ['type' => 'order_placed']);

        $this->assertSame(
            ['type' => 'order_placed', 'order_id' => '55', 'increment_id' => '000000055'],
            $this->sent[0]->data
        );
    }

    /**
     * This runs inside an order save. Nothing may escape.
     */
    public function testSwallowsAndLogsAnythingThatThrows(): void
    {
        $this->registry->method('findActiveToken')->willThrowException(new \RuntimeException('table is locked'));
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('table is locked'));

        $this->assertSame(0, $this->notifier()->notify($this->order('hash-1', 7), 'T', 'B'));
    }

    private function notifier(): OrderNotifier
    {
        return new OrderNotifier($this->config, $this->registry, $this->transport, $this->logger);
    }

    private function order(string $deviceHash, int $customerId): Order&MockObject
    {
        $state = new DataObject([OrderNotifier::FIELD_DEVICE_TOKEN_HASH => $deviceHash]);

        $order = $this->createMock(Order::class);
        $order->method('getData')->willReturnCallback(
            static fn ($key = '', $index = null) => $state->getData($key)
        );
        $order->method('getStoreId')->willReturn(1);
        $order->method('getCustomerId')->willReturn($customerId);
        $order->method('getEntityId')->willReturn(55);
        $order->method('getIncrementId')->willReturn('000000055');

        return $order;
    }
}
