<?php
declare(strict_types=1);

namespace Scr1be\AdminGridToolkit\Test\Unit\Model\Grid;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\AdminGridToolkit\Model\Grid\CountSelectDejoiner;

class CountSelectDejoinerTest extends TestCase
{
    /**
     * The columns of the joined table every test works with. Two of them — status and entity_id —
     * are deliberately names the main grid table has as well.
     */
    private const DELIVERY_ETA_COLUMNS = ['entity_id', 'order_id', 'eta_date', 'status'];

    private const MAIN_TABLE = [
        'joinType' => 'from',
        'schema' => null,
        'tableName' => 'sales_order_grid',
        'joinCondition' => null,
    ];

    private const ETA_JOIN = [
        'joinType' => 'left join',
        'schema' => null,
        'tableName' => 'delivery_eta',
        'joinCondition' => '`eta`.`order_id` = `main_table`.`entity_id`',
    ];

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testAnAllowlistedJoinNothingRefersToIsRemoved(): void
    {
        $from = ['main_table' => self::MAIN_TABLE, 'eta' => self::ETA_JOIN];
        $select = $this->select(['from' => $from]);

        $select->expects($this->once())
            ->method('setPart')
            ->with(Select::FROM, ['main_table' => self::MAIN_TABLE]);

        $this->dejoiner(['eta'])->stripUnusedJoins($select, $this->connection());
    }

    /**
     * @dataProvider referenceProvider
     */
    public function testAJoinTheCountStillRefersToStays(array $parts): void
    {
        $select = $this->select($parts + ['from' => ['main_table' => self::MAIN_TABLE, 'eta' => self::ETA_JOIN]]);
        $select->expects($this->never())->method('setPart');

        $this->dejoiner(['eta'])->stripUnusedJoins($select, $this->connection());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function referenceProvider(): array
    {
        return [
            'alias in the where clause' => [
                ['where' => ["(`eta`.`status` = 'late')"]],
            ],
            'table name in the where clause' => [
                ['where' => ['(delivery_eta.eta_date IS NOT NULL)']],
            ],
            'alias in the having clause' => [
                ['having' => ['(COUNT(`eta`.`order_id`) > 1)']],
            ],
            // A grid with a GROUP BY has its grouping columns folded into COUNT(DISTINCT ...) by
            // core before the plugin is handed the select — the group part itself is already gone.
            'alias inside the count expression' => [
                ['columns' => [['main_table', new \Zend_Db_Expr('COUNT(DISTINCT `eta`.`order_id`)'), null]]],
            ],
            // addFieldToFilter() renders a grid filter as a bare quoted identifier with no table
            // qualifier, so this is what a filter on a joined column actually looks like.
            'unqualified column of the joined table' => [
                ['where' => ["(`eta_date` >= '2026-01-01')"]],
            ],
        ];
    }

    /**
     * The mirror of the previous case: a qualified reference to a column the joined table happens
     * to share a name with says nothing about the join, and must not keep it alive.
     */
    public function testAQualifiedColumnOfAnotherTableDoesNotKeepTheJoin(): void
    {
        $from = ['main_table' => self::MAIN_TABLE, 'eta' => self::ETA_JOIN];
        $select = $this->select([
            'from' => $from,
            'where' => ["(`main_table`.`status` = 'complete')", '(`main_table`.`entity_id` > 100)'],
        ]);

        $select->expects($this->once())
            ->method('setPart')
            ->with(Select::FROM, ['main_table' => self::MAIN_TABLE]);

        $this->dejoiner(['eta'])->stripUnusedJoins($select, $this->connection());
    }

    public function testAJoinAnotherSurvivingJoinDependsOnStays(): void
    {
        $from = [
            'main_table' => self::MAIN_TABLE,
            'eta' => self::ETA_JOIN,
            'courier' => [
                'joinType' => 'left join',
                'schema' => null,
                'tableName' => 'courier',
                'joinCondition' => '`courier`.`entity_id` = `eta`.`courier_id`',
            ],
        ];
        $select = $this->select(['from' => $from]);
        $select->expects($this->never())->method('setPart');

        // Only eta is allowlisted, so courier stays — and courier cannot be joined to a table that
        // is no longer in the query.
        $this->dejoiner(['eta'])->stripUnusedJoins($select, $this->connection());
    }

    public function testAChainOfAllowlistedJoinsLeavesTogether(): void
    {
        $from = [
            'main_table' => self::MAIN_TABLE,
            'eta' => self::ETA_JOIN,
            'courier' => [
                'joinType' => 'left join',
                'schema' => null,
                'tableName' => 'courier',
                'joinCondition' => '`courier`.`entity_id` = `eta`.`courier_id`',
            ],
        ];
        $select = $this->select(['from' => $from]);

        $select->expects($this->once())
            ->method('setPart')
            ->with(Select::FROM, ['main_table' => self::MAIN_TABLE]);

        $this->dejoiner(['eta', 'courier'])->stripUnusedJoins($select, $this->connection());
    }

    /**
     * @dataProvider untouchableJoinProvider
     */
    public function testOnlyAllowlistedLeftJoinsAreEverRemoved(array $from, array $allowlist): void
    {
        $select = $this->select(['from' => $from]);
        $select->expects($this->never())->method('setPart');

        $this->dejoiner($allowlist)->stripUnusedJoins($select, $this->connection());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string[]}>
     */
    public static function untouchableJoinProvider(): array
    {
        return [
            'an inner join decides which rows exist' => [
                [
                    'main_table' => self::MAIN_TABLE,
                    'eta' => ['joinType' => 'inner join'] + self::ETA_JOIN,
                ],
                ['eta'],
            ],
            'the main table is not a join' => [
                ['main_table' => self::MAIN_TABLE],
                ['main_table'],
            ],
            'a join nobody allowlisted' => [
                ['main_table' => self::MAIN_TABLE, 'eta' => self::ETA_JOIN],
                ['loyalty'],
            ],
        ];
    }

    /**
     * A union assembles selects this one does not own, so the FROM in hand is not the whole query.
     */
    public function testASelectWithAUnionIsLeftAlone(): void
    {
        $select = $this->select([
            'from' => ['main_table' => self::MAIN_TABLE, 'eta' => self::ETA_JOIN],
            'union' => [['SELECT 1', Select::SQL_UNION_ALL]],
        ]);
        $select->expects($this->never())->method('setPart');

        $this->dejoiner(['eta'])->stripUnusedJoins($select, $this->connection());
    }

    /**
     * Without the joined table's column list the unqualified-filter question cannot be answered,
     * and an unanswered question is not permission to rewrite the query.
     */
    public function testAnUndescribableTableKeepsItsJoin(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('describeTable')->willThrowException(new \RuntimeException('no such table'));

        $select = $this->select(['from' => ['main_table' => self::MAIN_TABLE, 'eta' => self::ETA_JOIN]]);
        $select->expects($this->never())->method('setPart');
        $this->logger->expects($this->once())->method('warning');

        $this->dejoiner(['eta'])->stripUnusedJoins($select, $connection);
    }

    /**
     * The shipped configuration. Nothing is inspected at all, which is what makes the module inert
     * on an installation that has not opted in.
     */
    public function testAnEmptyAllowlistInspectsNothing(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects($this->never())->method('getPart');
        $select->expects($this->never())->method('setPart');

        $this->dejoiner([])->stripUnusedJoins($select, $this->connection());
    }

    /**
     * @param string[] $removableJoins
     */
    private function dejoiner(array $removableJoins): CountSelectDejoiner
    {
        return new CountSelectDejoiner($this->logger, $removableJoins);
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function select(array $parts): Select&MockObject
    {
        // What core hands over: ORDER, LIMIT and GROUP already reset, COLUMNS replaced by the count.
        $parts += ['columns' => [['main_table', new \Zend_Db_Expr('COUNT(*)'), null]]];

        $select = $this->createMock(Select::class);
        $select->method('getPart')->willReturnCallback(
            static fn (string $part): mixed => $parts[$part] ?? []
        );

        return $select;
    }

    private function connection(): AdapterInterface&MockObject
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('describeTable')->willReturnCallback(
            static fn (string $table): array => $table === 'delivery_eta'
                ? array_fill_keys(self::DELIVERY_ETA_COLUMNS, [])
                : []
        );

        return $connection;
    }
}
