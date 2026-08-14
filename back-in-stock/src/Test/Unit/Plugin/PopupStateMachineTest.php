<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Plugin;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Model\AbstractModel;
use Magento\ProductAlert\Model\ResourceModel\Stock as StockAlertResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\BackInStock\Model\AlertState;
use Scr1be\BackInStock\Model\ResourceModel\PopupStatusWriter;
use Scr1be\BackInStock\Plugin\PopupStateMachine;

/**
 * The state machine is the module's load-bearing class, so this is the longest of the specs.
 *
 * Every case here is a row core can produce: a brand-new subscription, a re-subscription over a
 * dismissed alert, a mail run marking an alert sent, and the same mail run running twice.
 */
class PopupStateMachineTest extends TestCase
{
    private PopupStatusWriter&MockObject $writer;
    private EventManager&MockObject $eventManager;
    private StockAlertResource&MockObject $resource;
    private PopupStateMachine $plugin;

    protected function setUp(): void
    {
        $this->writer = $this->createMock(PopupStatusWriter::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->resource = $this->createMock(StockAlertResource::class);
        $this->plugin = new PopupStateMachine($this->writer, $this->eventManager);
    }

    public function testABrandNewAlertChangesNothing(): void
    {
        // `_beforeSave()` found no existing row, so the object carries no popup_status at all and
        // `status` is the 0 it just set. There is nothing to queue and nothing to reset.
        $object = $this->alert(['status' => AlertState::ALERT_ARMED]);

        $this->writer->expects($this->never())->method('transition');
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->plugin->afterSave($this->resource, $this->resource, $object);
    }

    public function testASentAlertIsQueuedAndAnnounced(): void
    {
        $object = $this->alert([
            'status' => AlertState::ALERT_SENT,
            'popup_status' => AlertState::POPUP_IDLE,
            'customer_id' => 7,
            'product_id' => 42,
            'website_id' => 1,
            'store_id' => 3,
        ]);

        $this->writer->expects($this->once())
            ->method('transition')
            ->with(99, AlertState::POPUP_IDLE, AlertState::POPUP_QUEUED)
            ->willReturn(true);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with(
                PopupStateMachine::EVENT_ALERT_QUEUED,
                [
                    'alert_id' => 99,
                    'customer_id' => 7,
                    'product_id' => 42,
                    'website_id' => 1,
                    'store_id' => 3,
                ]
            );

        $this->plugin->afterSave($this->resource, $this->resource, $object);

        $this->assertSame(AlertState::POPUP_QUEUED, $object->getData('popup_status'));
    }

    public function testTheEventDoesNotFireWhenAnotherProcessQueuedTheAlertFirst(): void
    {
        // Two application servers running the same mail run. The compare-and-set is what decides
        // which of them owns the notification, and the loser must not send a second one.
        $object = $this->alert([
            'status' => AlertState::ALERT_SENT,
            'popup_status' => AlertState::POPUP_IDLE,
        ]);

        $this->writer->method('transition')->willReturn(false);
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->plugin->afterSave($this->resource, $this->resource, $object);
    }

    public function testAnAlreadyQueuedAlertIsLeftAlone(): void
    {
        $object = $this->alert([
            'status' => AlertState::ALERT_SENT,
            'popup_status' => AlertState::POPUP_QUEUED,
        ]);

        $this->writer->expects($this->never())->method('transition');

        $this->plugin->afterSave($this->resource, $this->resource, $object);
    }

    /**
     * The bug the whole plugin exists for.
     *
     * `Magento\ProductAlert\Model\ResourceModel\Stock::_beforeSave()` merges the existing row into a
     * freshly built model and forces `status` back to 0 — which brings the old `popup_status` back
     * with it. Without this reset the alert can never be queued again, because the queue transition
     * is only ever from POPUP_IDLE.
     */
    public function testResubscribingResetsAPopupStateThatWouldSuppressTheAlertForever(): void
    {
        $object = $this->alert([
            'status' => AlertState::ALERT_ARMED,
            'popup_status' => AlertState::POPUP_SHOWN,
        ]);

        $this->writer->expects($this->once())
            ->method('transition')
            ->with(99, AlertState::POPUP_SHOWN, AlertState::POPUP_IDLE)
            ->willReturn(true);

        $this->eventManager->expects($this->never())->method('dispatch');

        $this->plugin->afterSave($this->resource, $this->resource, $object);

        $this->assertSame(AlertState::POPUP_IDLE, $object->getData('popup_status'));
    }

    public function testResubscribingOverAQueuedButUnseenAlertAlsoResets(): void
    {
        // Less common than the dismissed case and just as wrong to leave: the customer re-subscribed
        // before opening the popup, so the queued state describes a subscription that is over.
        $object = $this->alert([
            'status' => AlertState::ALERT_ARMED,
            'popup_status' => AlertState::POPUP_QUEUED,
        ]);

        $this->writer->expects($this->once())
            ->method('transition')
            ->with(99, AlertState::POPUP_QUEUED, AlertState::POPUP_IDLE)
            ->willReturn(true);

        $this->plugin->afterSave($this->resource, $this->resource, $object);
    }

    public function testAnObjectWithNoIdIsIgnored(): void
    {
        // `AbstractDb::save()` returns early without writing when the object has no data changes.
        // Reaching for an id that is not there would address row zero.
        $object = $this->alert(['status' => AlertState::ALERT_SENT], 0);

        $this->writer->expects($this->never())->method('transition');

        $this->plugin->afterSave($this->resource, $this->resource, $object);
    }

    public function testTheDirtyFlagIsPutBackAfterSyncing(): void
    {
        // `AbstractDb::save()` lowers it on the way out; `AbstractModel::setData()` raises it again.
        // Leaving it raised turns the next save of this object into a full row rewrite.
        $object = $this->alert([
            'status' => AlertState::ALERT_SENT,
            'popup_status' => AlertState::POPUP_IDLE,
        ]);
        $object->setHasDataChanges(false);

        $this->writer->method('transition')->willReturn(true);

        $this->plugin->afterSave($this->resource, $this->resource, $object);

        $this->assertFalse($object->hasDataChanges());
    }

    public function testTheResourceModelIsReturnedUntouched(): void
    {
        $object = $this->alert(['status' => AlertState::ALERT_SENT]);

        $this->assertSame(
            $this->resource,
            $this->plugin->afterSave($this->resource, $this->resource, $object)
        );
    }

    /**
     * A real `AbstractModel` with its constructor skipped: `setData()`, `getData()`,
     * `setOrigData()` and the dirty flag all run their own implementations, which is the point —
     * the plugin's contract with them is what is under test.
     *
     * @param array<string, int> $data
     */
    private function alert(array $data, int $id = 99): AbstractModel
    {
        $object = $this->getMockBuilder(AbstractModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMockForAbstractClass();

        $object->method('getId')->willReturn($id);
        $object->setData($data);

        return $object;
    }
}
