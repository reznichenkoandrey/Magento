<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Model\ResourceModel\ArrivalIndex;

class ArrivalIndexTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private ArrivalIndex $arrivalIndex;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => 'prefix_' . $table);

        $this->arrivalIndex = new ArrivalIndex($resourceConnection);
    }

    /**
     * The single most drift-prone line in the module.
     *
     * `Magento\Framework\DB\Adapter\Pdo\Mysql::insertOnDuplicate()` replaces an **empty** `$fields`
     * list with every column — so the intuitive "no columns to update" spelling would overwrite
     * `arrived_at` on every restock and silently destroy the one fact this table exists to hold.
     * Nominating the primary key is what makes the conflict clause a no-op, and this assertion is
     * what keeps someone from "simplifying" it back.
     */
    public function testFirstArrivalWinsBecauseTheConflictClauseIsANoOp(): void
    {
        $this->connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                'prefix_scr1be_curated_arrival',
                ['product_id' => 7, 'arrived_at' => '2026-08-14 09:00:00'],
                ['product_id']
            );

        $this->arrivalIndex->recordArrival(7, '2026-08-14 09:00:00');
    }

    public function testIgnoresAnImpossibleProductId(): void
    {
        $this->connection->expects($this->never())->method('insertOnDuplicate');

        $this->arrivalIndex->recordArrival(0, '2026-08-14 09:00:00');
    }

    public function testReturnsNullWhenTheProductHasNeverBeenInStock(): void
    {
        $this->connection->method('select')->willReturn($this->select());
        $this->connection->method('fetchOne')->willReturn(false);

        $this->assertNull($this->arrivalIndex->getArrivalDate(7));
    }

    public function testReturnsTheStoredArrivalDate(): void
    {
        $this->connection->method('select')->willReturn($this->select());
        $this->connection->method('fetchOne')->willReturn('2026-01-02 03:04:05');

        $this->assertSame('2026-01-02 03:04:05', $this->arrivalIndex->getArrivalDate(7));
    }

    public function testRecentArrivalsComeBackAsIntegers(): void
    {
        $this->connection->method('select')->willReturn($this->select());
        $this->connection->method('fetchCol')->willReturn(['21', '22']);

        $this->assertSame([21, 22], $this->arrivalIndex->getRecentArrivals('2026-07-14 00:00:00', 10));
    }

    public function testAsksForNothingWhenTheLimitIsMeaningless(): void
    {
        $this->connection->expects($this->never())->method('select');

        $this->assertSame([], $this->arrivalIndex->getRecentArrivals('2026-07-14 00:00:00', 0));
    }

    private function select(): Select&MockObject
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        return $select;
    }
}
