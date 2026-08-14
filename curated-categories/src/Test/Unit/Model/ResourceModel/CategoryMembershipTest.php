<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Model\ResourceModel\CategoryMembership;

/**
 * The seam between the engine and `catalog_category_product`. Every assertion here is about the
 * exact shape of a call into a Magento API — the place a module drifts when the framework moves and
 * nothing in the engine's own tests would notice.
 */
class CategoryMembershipTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private CategoryMembership $membership;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => 'prefix_' . $table);

        $this->membership = new CategoryMembership($resourceConnection);
    }

    public function testMembershipComesBackKeyedByProductIdWithIntegerPositions(): void
    {
        $this->connection->method('select')->willReturn($this->select());
        $this->connection->method('fetchAll')->willReturn([
            ['product_id' => '10', 'position' => '3'],
            ['product_id' => '11', 'position' => '0'],
        ]);

        $this->assertSame([10 => 3, 11 => 0], $this->membership->getMembership(5));
    }

    /**
     * `position` as the only update column is what makes a re-rank one integer write instead of a
     * delete plus an insert — which on a table two mview views subscribe to would put the same
     * product in the changelog twice.
     */
    public function testUpsertUpdatesOnlyThePosition(): void
    {
        $this->connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                'prefix_catalog_category_product',
                [
                    ['category_id' => 5, 'product_id' => 10, 'position' => 1],
                    ['category_id' => 5, 'product_id' => 11, 'position' => 2],
                ],
                ['position']
            )
            ->willReturn(2);

        $this->assertSame(2, $this->membership->upsert(5, [10 => 1, 11 => 2]));
    }

    /**
     * MySQL builds one packet per statement, so an unbounded feed has to be chunked or a large
     * reconcile meets `max_allowed_packet`. Six hundred rows is two statements at the documented
     * batch size of five hundred.
     */
    public function testUpsertChunksLargeFeeds(): void
    {
        $positions = [];
        for ($productId = 1; $productId <= 600; $productId++) {
            $positions[$productId] = $productId;
        }

        $batchSizes = [];
        $this->connection->expects($this->exactly(2))
            ->method('insertOnDuplicate')
            ->willReturnCallback(static function (string $table, array $rows) use (&$batchSizes): int {
                $batchSizes[] = count($rows);

                return count($rows);
            });

        $this->assertSame(600, $this->membership->upsert(5, $positions));
        $this->assertSame([500, 100], $batchSizes);
    }

    public function testUpsertDoesNotQueryForAnEmptyFeed(): void
    {
        $this->connection->expects($this->never())->method('insertOnDuplicate');

        $this->assertSame(0, $this->membership->upsert(5, []));
    }

    public function testRemoveScopesTheDeleteToOneCategory(): void
    {
        $this->connection->expects($this->once())
            ->method('delete')
            ->with(
                'prefix_catalog_category_product',
                [
                    'category_id = ?' => 5,
                    'product_id IN (?)' => [10, 11],
                ]
            )
            ->willReturn(2);

        $this->assertSame(2, $this->membership->remove(5, [10, 11]));
    }

    public function testRemoveDoesNotQueryForAnEmptyList(): void
    {
        $this->connection->expects($this->never())->method('delete');

        $this->assertSame(0, $this->membership->remove(5, []));
    }

    /**
     * The pivot's foreign key means one stale id does not skip a row, it aborts the whole upsert.
     * The filter has to drop the missing ones and leave the source's ranking untouched.
     */
    public function testFilterDropsMissingProductsAndPreservesRanking(): void
    {
        $this->connection->method('select')->willReturn($this->select());
        $this->connection->method('fetchCol')->willReturn(['30', '10']);

        $this->assertSame([10, 30], $this->membership->filterExistingProducts([10, 20, 30]));
    }

    public function testFilterDoesNotQueryForAnEmptyList(): void
    {
        $this->connection->expects($this->never())->method('select');

        $this->assertSame([], $this->membership->filterExistingProducts([]));
    }

    private function select(): Select&MockObject
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }
}
