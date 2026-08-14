<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Model\LinkPlan;
use Scr1be\ProductFamilies\Model\ResourceModel\LinkPositionAttribute;
use Scr1be\ProductFamilies\Model\ResourceModel\LinkWriter;

/**
 * The seam. Everything above this class is pure PHP the unit tests can reason about; here the
 * module meets three framework contracts it does not own — `insertMultiple`, `insertOnDuplicate`'s
 * update-field list, and the cascade behind the delete — and those are exactly the places a
 * refactor drifts without anything failing loudly.
 */
class LinkWriterTest extends TestCase
{
    private const LINK_TYPE_ID = 21;
    private const POSITION_ATTRIBUTE_ID = 7;

    private AdapterInterface&MockObject $connection;
    private LinkWriter $writer;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $positionAttribute = $this->createMock(LinkPositionAttribute::class);
        $positionAttribute->method('getId')->willReturn(self::POSITION_ATTRIBUTE_ID);

        $this->writer = new LinkWriter($resource, $positionAttribute);
    }

    public function testInsertsCarryOnlyTheThreeColumnsTheLinkTableNeeds(): void
    {
        $this->givenLinkIdsForInsertedPairs([['link_id' => 900, 'product_id' => 1, 'linked_product_id' => 2]]);

        $this->connection->expects($this->once())
            ->method('insertMultiple')
            ->with(
                'catalog_product_link',
                [['product_id' => 1, 'linked_product_id' => 2, 'link_type_id' => self::LINK_TYPE_ID]]
            );

        $this->writer->apply(
            new LinkPlan([['product_id' => 1, 'linked_product_id' => 2, 'position' => 4]], [], [], [1], 0),
            self::LINK_TYPE_ID
        );
    }

    /**
     * `catalog_product_link_attribute_int` has a unique key over
     * (product_link_attribute_id, link_id). Passing `['value']` as the update field list is what
     * turns the statement into an upsert against that key; omitting it would grow a second value row
     * for the same link on every re-rank.
     */
    public function testPositionsAreUpsertedOnTheValueColumnOnly(): void
    {
        $this->givenLinkIdsForInsertedPairs([]);

        $this->connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                'catalog_product_link_attribute_int',
                [
                    [
                        'product_link_attribute_id' => self::POSITION_ATTRIBUTE_ID,
                        'link_id' => 55,
                        'value' => 3,
                    ],
                ],
                ['value']
            );

        $this->writer->apply(
            new LinkPlan([], [['link_id' => 55, 'position' => 3]], [], [1], 0),
            self::LINK_TYPE_ID
        );
    }

    /**
     * The read-back exists because `lastInsertId()` after a multi-row insert reports the first row
     * only. This asserts that the position each new link gets is the one the plan asked for, matched
     * by pair rather than by the order rows came back in.
     */
    public function testNewLinksGetTheirPlannedPositionMatchedByPair(): void
    {
        $this->givenLinkIdsForInsertedPairs([
            ['link_id' => 902, 'product_id' => 1, 'linked_product_id' => 3],
            ['link_id' => 901, 'product_id' => 1, 'linked_product_id' => 2],
            // A pre-existing link of the same product that the plan says nothing about.
            ['link_id' => 800, 'product_id' => 1, 'linked_product_id' => 99],
        ]);

        $this->connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                'catalog_product_link_attribute_int',
                [
                    ['product_link_attribute_id' => self::POSITION_ATTRIBUTE_ID, 'link_id' => 902, 'value' => 2],
                    ['product_link_attribute_id' => self::POSITION_ATTRIBUTE_ID, 'link_id' => 901, 'value' => 1],
                ],
                ['value']
            );

        $this->writer->apply(
            new LinkPlan(
                [
                    ['product_id' => 1, 'linked_product_id' => 2, 'position' => 1],
                    ['product_id' => 1, 'linked_product_id' => 3, 'position' => 2],
                ],
                [],
                [],
                [1],
                0
            ),
            self::LINK_TYPE_ID
        );
    }

    public function testInsertsAreChunkedAtTheBatchSize(): void
    {
        $this->givenLinkIdsForInsertedPairs([]);

        $inserts = [];
        for ($i = 1; $i <= LinkWriter::BATCH_SIZE + 1; $i++) {
            $inserts[] = ['product_id' => 1, 'linked_product_id' => $i + 1, 'position' => $i];
        }

        $sizes = [];
        $this->connection->expects($this->exactly(2))
            ->method('insertMultiple')
            ->willReturnCallback(static function (string $table, array $rows) use (&$sizes): int {
                $sizes[] = count($rows);

                return count($rows);
            });

        $this->writer->apply(new LinkPlan($inserts, [], [], [1], 0), self::LINK_TYPE_ID);

        $this->assertSame([LinkWriter::BATCH_SIZE, 1], $sizes);
    }

    /**
     * Only the link rows are deleted. `catalog_product_link_attribute_int`'s foreign key on
     * `link_id` is declared `onDelete="CASCADE"` in `Magento_Catalog`'s `db_schema.xml`, so the
     * position rows go with them — a second delete here would be dead code that looked load-bearing.
     */
    public function testDeletesTouchTheLinkTableOnlyAndAreChunked(): void
    {
        $deletes = range(1, LinkWriter::BATCH_SIZE + 2);

        $chunks = [];
        $this->connection->expects($this->exactly(2))
            ->method('delete')
            ->willReturnCallback(static function (string $table, array $where) use (&$chunks): int {
                $chunks[] = [$table, count($where['link_id IN (?)'])];

                return 1;
            });

        $this->writer->apply(new LinkPlan([], [], $deletes, [1], 0), self::LINK_TYPE_ID);

        $this->assertSame(
            [['catalog_product_link', LinkWriter::BATCH_SIZE], ['catalog_product_link', 2]],
            $chunks
        );
    }

    public function testAnEmptyPlanIssuesNoStatements(): void
    {
        $this->connection->expects($this->never())->method('insertMultiple');
        $this->connection->expects($this->never())->method('insertOnDuplicate');
        $this->connection->expects($this->never())->method('delete');
        $this->connection->expects($this->never())->method('select');

        $this->writer->apply(new LinkPlan([], [], [], [], 0), self::LINK_TYPE_ID);
    }

    public function testCurrentStateIsKeyedByProductThenLinkedProduct(): void
    {
        $this->givenSelect();
        $this->connection->method('fetchAll')->willReturn([
            ['link_id' => '11', 'product_id' => '1', 'linked_product_id' => '2', 'position' => '3'],
            // A link whose position row is missing reads as zero rather than being dropped, so the
            // next run corrects it instead of leaving it invisible to the diff.
            ['link_id' => '12', 'product_id' => '1', 'linked_product_id' => '4', 'position' => null],
        ]);

        $this->assertSame(
            [
                1 => [
                    2 => ['link_id' => 11, 'position' => 3],
                    4 => ['link_id' => 12, 'position' => 0],
                ],
            ],
            $this->writer->readCurrent(self::LINK_TYPE_ID)
        );
    }

    /**
     * @param array<int, array<string, int|string>> $rows
     */
    private function givenLinkIdsForInsertedPairs(array $rows): void
    {
        $this->givenSelect();
        $this->connection->method('fetchAll')->willReturn($rows);
    }

    private function givenSelect(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        $this->connection->method('select')->willReturn($select);
    }
}
