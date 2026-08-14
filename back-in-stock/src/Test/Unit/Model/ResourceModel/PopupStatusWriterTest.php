<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\BackInStock\Model\AlertState;
use Scr1be\BackInStock\Model\ResourceModel\PopupStatusWriter;

/**
 * The seam, and the security boundary.
 *
 * Every assertion here is about a WHERE clause. Alert ids arrive from a browser and address rows in
 * a table shared by every customer on the installation, so "the customer id is in the UPDATE" is not
 * a stylistic preference — it is the only thing standing between a forged id and somebody else's
 * alert.
 */
class PopupStatusWriterTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private PopupStatusWriter $writer;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->writer = new PopupStatusWriter($resource);
    }

    public function testATransitionIsACompareAndSet(): void
    {
        // Without the `popup_status = :from` clause two processes both "queue" the same alert and
        // both believe they were first, which is one restock and two push notifications.
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                AlertState::TABLE,
                ['popup_status' => AlertState::POPUP_QUEUED],
                [
                    'alert_stock_id = ?' => 12,
                    'popup_status = ?' => AlertState::POPUP_IDLE,
                ]
            )
            ->willReturn(1);

        $this->assertTrue($this->writer->transition(12, AlertState::POPUP_IDLE, AlertState::POPUP_QUEUED));
    }

    public function testATransitionThatChangedNothingReportsFalse(): void
    {
        $this->connection->method('update')->willReturn(0);

        $this->assertFalse($this->writer->transition(12, AlertState::POPUP_IDLE, AlertState::POPUP_QUEUED));
    }

    public function testATransitionToTheSameStateNeverReachesTheDatabase(): void
    {
        $this->connection->expects($this->never())->method('update');

        $this->assertFalse($this->writer->transition(12, AlertState::POPUP_QUEUED, AlertState::POPUP_QUEUED));
    }

    public function testDismissalIsScopedToTheSessionsCustomerAndWebsite(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                AlertState::TABLE,
                ['popup_status' => AlertState::POPUP_SHOWN],
                [
                    'alert_stock_id IN (?)' => [4, 9],
                    'customer_id = ?' => 7,
                    'website_id = ?' => 1,
                    'popup_status = ?' => AlertState::POPUP_QUEUED,
                ]
            )
            ->willReturn(2);

        $this->assertSame(2, $this->writer->markShown(7, 1, [4, 9]));
    }

    public function testForgedIdsAreNormalisedRatherThanTrusted(): void
    {
        // The ids come off a JSON payload, so strings, duplicates, zeroes and negatives all arrive.
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(static fn (array $where): bool => $where['alert_stock_id IN (?)'] === [4, 9])
            )
            ->willReturn(2);

        $this->writer->markShown(7, 1, ['4', 4, '9', '0', -1, 'nonsense']);
    }

    public function testAnEmptyIdListIsNotAnUpdateOfEveryRow(): void
    {
        $this->connection->expects($this->never())->method('update');

        $this->assertSame(0, $this->writer->markShown(7, 1, []));
    }

    public function testAGuestCannotDismissAnything(): void
    {
        $this->connection->expects($this->never())->method('update');

        $this->assertSame(0, $this->writer->markShown(0, 1, [4]));
        $this->assertSame(0, $this->writer->markAllShown(0, 1));
    }

    public function testTheResetOnlyEverTouchesAlertsCoreHasMarkedSent(): void
    {
        // Re-queueing an alert that never fired would put a card for an out-of-stock product in
        // front of a customer, which is the one thing this module exists not to do.
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                AlertState::TABLE,
                ['popup_status' => AlertState::POPUP_QUEUED],
                [
                    'status = ?' => AlertState::ALERT_SENT,
                    'popup_status = ?' => AlertState::POPUP_SHOWN,
                    'customer_id = ?' => 7,
                ]
            )
            ->willReturn(3);

        $this->assertSame(3, $this->writer->requeueSent(7, null));
    }

    public function testTheResetCanBeGlobal(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                AlertState::TABLE,
                $this->anything(),
                [
                    'status = ?' => AlertState::ALERT_SENT,
                    'popup_status = ?' => AlertState::POPUP_SHOWN,
                ]
            )
            ->willReturn(11);

        $this->assertSame(11, $this->writer->requeueSent(null, null));
    }
}
