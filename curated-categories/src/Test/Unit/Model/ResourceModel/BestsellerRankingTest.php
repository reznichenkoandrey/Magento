<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Model\ResourceModel\BestsellerRanking;

class BestsellerRankingTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private BestsellerRanking $ranking;

    /** @var array<int, array{0: string, 1: mixed}> */
    private array $whereClauses = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => 'prefix_' . $table);

        $this->ranking = new BestsellerRanking($resourceConnection);
    }

    public function testAsksForNothingWhenTheLimitIsMeaningless(): void
    {
        $this->connection->expects($this->never())->method('select');

        $this->assertSame([], $this->ranking->getTopProductIds('2026-07-15 00:00:00', 0));
    }

    /**
     * Three clauses are the correctness of this query, and all three are the kind of thing a later
     * edit removes without noticing:
     *
     * - paid states only, so a `pending_payment` shopper and a cancelled sale do not rank;
     * - `parent_item_id IS NULL`, so a configurable's simple child — which carries no category
     *   assignment — is not counted instead of the product that does;
     * - a non-null product id, because the column is nullable.
     */
    public function testRestrictsToPaidTopLevelOrderItemsInsideTheWindow(): void
    {
        $this->connection->method('select')->willReturn($this->recordingSelect());
        $this->connection->method('fetchCol')->willReturn([]);

        $this->ranking->getTopProductIds('2026-07-15 09:00:00', 24);

        $this->assertContains(['o.created_at >= ?', '2026-07-15 09:00:00'], $this->whereClauses);
        $this->assertContains(
            ['o.state IN (?)', [Order::STATE_PROCESSING, Order::STATE_COMPLETE]],
            $this->whereClauses
        );
        $this->assertContains(['oi.parent_item_id IS NULL', null], $this->whereClauses);
        $this->assertContains(['oi.product_id IS NOT NULL', null], $this->whereClauses);
    }

    public function testProductIdsComeBackAsIntegers(): void
    {
        $this->connection->method('select')->willReturn($this->recordingSelect());
        $this->connection->method('fetchCol')->willReturn(['5', '9']);

        $this->assertSame([5, 9], $this->ranking->getTopProductIds('2026-07-15 09:00:00', 24));
    }

    private function recordingSelect(): Select&MockObject
    {
        $select = $this->createMock(Select::class);

        foreach (['from', 'join', 'columns', 'group', 'having', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }

        $select->method('where')->willReturnCallback(
            function (string $condition, mixed $value = null) use ($select): Select {
                $this->whereClauses[] = [$condition, $value];

                return $select;
            }
        );

        return $select;
    }
}
