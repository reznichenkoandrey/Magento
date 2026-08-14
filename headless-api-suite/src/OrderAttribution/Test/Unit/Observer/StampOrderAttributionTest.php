<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\OrderAttribution\Model\Attribution;
use Scr1be\OrderAttribution\Model\AttributionHolder;
use Scr1be\OrderAttribution\Model\OrderAttributionFields;
use Scr1be\OrderAttribution\Observer\StampOrderAttribution;

/**
 * The observer writes two columns and must never break a checkout doing it.
 */
class StampOrderAttributionTest extends TestCase
{
    private AttributionHolder $holder;
    private LoggerInterface&MockObject $logger;
    private StampOrderAttribution $observer;

    protected function setUp(): void
    {
        $this->holder = new AttributionHolder();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->observer = new StampOrderAttribution($this->holder, $this->logger);
    }

    public function testWritesBothColumnsOntoTheOrder(): void
    {
        $this->holder->push(Attribution::of('ios-app', 'build 412'));
        $order = $this->order();

        $this->observer->execute($this->observerFor(['order' => $order]));

        $this->assertSame('ios-app', $order->getData(OrderAttributionFields::SOURCE_CODE));
        $this->assertSame('build 412', $order->getData(OrderAttributionFields::SOURCE_DETAIL));
    }

    public function testWritesNothingWhenNoAttributionIsCurrent(): void
    {
        $order = $this->order();

        $this->observer->execute($this->observerFor(['order' => $order]));

        $this->assertNull($order->getData(OrderAttributionFields::SOURCE_CODE));
    }

    /**
     * A source with no detail must clear the column rather than leave whatever was there — the order
     * object is built fresh each time, but an explicit null documents the intent and keeps the two
     * columns consistent.
     */
    public function testANullDetailIsWrittenAsNull(): void
    {
        $this->holder->push(Attribution::of('web', null));
        $order = $this->order();

        $this->observer->execute($this->observerFor(['order' => $order]));

        $this->assertSame('web', $order->getData(OrderAttributionFields::SOURCE_CODE));
        $this->assertNull($order->getData(OrderAttributionFields::SOURCE_DETAIL));
    }

    /**
     * The event payload is not this module's to guarantee.
     */
    public function testIgnoresAnEventWithoutAnOrder(): void
    {
        $this->holder->push(Attribution::of('web', null));

        $this->observer->execute($this->observerFor(['quote' => new DataObject()]));

        $this->addToAssertionCount(1);
    }

    /**
     * Attribution is analytics. A checkout must not fail because a reporting column could not be
     * written.
     */
    public function testSwallowsAndLogsAnythingThatThrows(): void
    {
        $this->holder->push(Attribution::of('web', null));
        $order = $this->createMock(Order::class);
        $order->method('setData')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('boom'));

        $this->observer->execute($this->observerFor(['order' => $order]));
    }

    private function order(): Order&MockObject
    {
        $state = new DataObject();
        $order = $this->createMock(Order::class);
        $order->method('setData')->willReturnCallback(
            static function ($key, $value = null) use ($state, &$order) {
                $state->setData($key, $value);

                return $order;
            }
        );
        $order->method('getData')->willReturnCallback(
            static fn ($key = '', $index = null) => $state->getData($key)
        );

        return $order;
    }

    /**
     * @param array<string, mixed> $data
     * @return Observer
     */
    private function observerFor(array $data): Observer
    {
        return new Observer(['event' => new Event($data)]);
    }
}
