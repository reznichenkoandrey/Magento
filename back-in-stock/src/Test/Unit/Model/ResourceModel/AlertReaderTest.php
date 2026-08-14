<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\BackInStock\Model\AlertState;
use Scr1be\BackInStock\Model\ResourceModel\AlertReader;

class AlertReaderTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private AlertReader $reader;

    protected function setUp(): void
    {
        $this->select = $this->createMock(Select::class);
        foreach (['from', 'where', 'order', 'limit'] as $method) {
            $this->select->method($method)->willReturnSelf();
        }

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($this->select);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->reader = new AlertReader($resource);
    }

    public function testTheQueuedReadIsOrderedNewestFirstWithAStableTieBreak(): void
    {
        // A mail run marks a batch of alerts inside the same second, so `send_date` alone leaves the
        // card order up to the storage engine — and a popup that reshuffles between page loads reads
        // as a bug.
        $this->select->expects($this->once())
            ->method('order')
            ->with(['send_date DESC', 'alert_stock_id DESC'])
            ->willReturnSelf();

        $this->connection->method('fetchAll')->willReturn([]);

        $this->reader->readQueued(7, 1, 6);
    }

    public function testTheQueuedReadIsLimitedToWhatThePopupWillShow(): void
    {
        $this->select->expects($this->once())->method('limit')->with(6)->willReturnSelf();
        $this->connection->method('fetchAll')->willReturn([]);

        $this->reader->readQueued(7, 1, 6);
    }

    public function testTheQueuedReadFiltersOnTheQueuedStateAlone(): void
    {
        // `popup_status = QUEUED` implies `status = SENT`, because the only transition into QUEUED
        // is from a save that set the status. A redundant second clause would be a second thing to
        // keep in step with the state machine.
        $clauses = [];
        $this->select->method('where')->willReturnCallback(
            function (string $condition, $value) use (&$clauses): Select {
                $clauses[$condition] = $value;

                return $this->select;
            }
        );
        $this->connection->method('fetchAll')->willReturn([]);

        $this->reader->readQueued(7, 1, 6);

        $this->assertSame(
            [
                'customer_id = ?' => 7,
                'website_id = ?' => 1,
                'popup_status = ?' => AlertState::POPUP_QUEUED,
            ],
            $clauses
        );
    }

    public function testIdColumnsComeBackAsIntegers(): void
    {
        // MySQL hands every column back as a string, and the state machine compares popup_status
        // with `===`. A "1" would fail every one of those comparisons silently.
        $this->connection->method('fetchAll')->willReturn([
            ['alert_stock_id' => '4', 'product_id' => '42', 'send_date' => '2026-06-01 09:00:00'],
        ]);

        $this->assertSame(
            [['alert_stock_id' => 4, 'product_id' => 42, 'send_date' => '2026-06-01 09:00:00']],
            $this->reader->readQueued(7, 1, 6)
        );
    }

    public function testTheAccountReadCarriesBothStateColumns(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            [
                'alert_stock_id' => '4',
                'product_id' => '42',
                'status' => '1',
                'popup_status' => '2',
                'add_date' => '2026-05-01 09:00:00',
                'send_date' => null,
            ],
        ]);

        $row = $this->reader->readAll(7, 1)[0];

        $this->assertSame(1, $row['status']);
        $this->assertSame(2, $row['popup_status']);
        $this->assertNull($row['send_date']);
    }

    public function testAGuestIsNeverQueriedFor(): void
    {
        $this->connection->expects($this->never())->method('fetchAll');

        $this->assertSame([], $this->reader->readQueued(0, 1, 6));
        $this->assertSame([], $this->reader->readAll(0, 1));
    }

    public function testAZeroLimitIsNotAnUnboundedRead(): void
    {
        $this->connection->expects($this->never())->method('fetchAll');

        $this->assertSame([], $this->reader->readQueued(7, 1, 0));
    }
}
