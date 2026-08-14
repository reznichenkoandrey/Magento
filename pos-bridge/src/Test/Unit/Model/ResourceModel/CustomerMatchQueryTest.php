<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\PosBridge\Model\ResourceModel\CustomerMatchQuery;
use Scr1be\PosBridge\Model\Search\CustomerColumns;
use Scr1be\PosBridge\Model\Search\MatchConditionBuilder;
use Scr1be\PosBridge\Model\Search\Token;

/**
 * The second half of the database seam. What matters here is not the SQL text — that is the
 * condition builder's test — but the assembly: that tokens are AND-ed as separate WHERE clauses,
 * that the website filter is only added when asked for, that the cap is over-fetched by exactly one
 * row, and that the result is ordered at all.
 *
 * Every `Select` call is recorded rather than asserted with `expects()`, so one run of the query
 * answers every question the test has and the mock never has to carry two matchers for one method.
 */
class CustomerMatchQueryTest extends TestCase
{
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private MatchConditionBuilder&MockObject $conditionBuilder;
    private CustomerMatchQuery $query;

    /** @var array<int, array{table: mixed, columns: mixed}> */
    private array $fromCalls = [];
    /** @var array<int, array{table: mixed, condition: mixed, columns: mixed}> */
    private array $joinCalls = [];
    /** @var array<int, array{0: mixed, 1: mixed, 2: mixed}> */
    private array $whereCalls = [];
    /** @var array<int, mixed> */
    private array $orderCalls = [];
    /** @var array<int, mixed> */
    private array $limitCalls = [];

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->select = $this->createMock(Select::class);
        $this->conditionBuilder = $this->createMock(MatchConditionBuilder::class);

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => 'pfx_' . $table);
        $this->connection->method('select')->willReturn($this->select);
        $this->connection->method('fetchAll')->willReturn([]);

        $this->select->method('from')->willReturnCallback(
            function ($table, $columns = '*'): Select {
                $this->fromCalls[] = ['table' => $table, 'columns' => $columns];

                return $this->select;
            }
        );
        $this->select->method('joinLeft')->willReturnCallback(
            function ($table, $condition, $columns = '*'): Select {
                $this->joinCalls[] = ['table' => $table, 'condition' => $condition, 'columns' => $columns];

                return $this->select;
            }
        );
        $this->select->method('where')->willReturnCallback(
            function ($condition, $value = null, $type = null): Select {
                $this->whereCalls[] = [$condition, $value, $type];

                return $this->select;
            }
        );
        $this->select->method('order')->willReturnCallback(
            function ($spec): Select {
                $this->orderCalls[] = $spec;

                return $this->select;
            }
        );
        $this->select->method('limit')->willReturnCallback(
            function ($count = null): Select {
                $this->limitCalls[] = $count;

                return $this->select;
            }
        );

        $this->conditionBuilder->method('forToken')->willReturnCallback(
            static fn (AdapterInterface $connection, Token $token): string
                => '(match ' . $token->getTerm() . ')'
        );

        $this->query = new CustomerMatchQuery($this->resourceConnection, $this->conditionBuilder);
    }

    public function testTheCustomerTableIsJoinedToItsDefaultBillingAddress(): void
    {
        $this->query->fetch([new Token('smith')], null, 20);

        $this->assertCount(1, $this->fromCalls);
        $this->assertSame(
            [CustomerColumns::CUSTOMER_ALIAS => 'pfx_customer_entity'],
            $this->fromCalls[0]['table']
        );
        $this->assertSame('entity_id', $this->fromCalls[0]['columns'][CustomerColumns::CUSTOMER_ID]);
        $this->assertSame('group_id', $this->fromCalls[0]['columns'][CustomerColumns::GROUP_ID]);

        $this->assertCount(1, $this->joinCalls);
        $this->assertSame(
            [CustomerColumns::BILLING_ALIAS => 'pfx_customer_address_entity'],
            $this->joinCalls[0]['table']
        );
        $this->assertSame('billing.entity_id = customer.default_billing', $this->joinCalls[0]['condition']);
        $this->assertSame('telephone', $this->joinCalls[0]['columns'][CustomerColumns::BILLING_TELEPHONE]);
    }

    /**
     * One WHERE per token. They are separate clauses on purpose — `Select::where()` joins them with
     * AND, which is exactly the "every word must match something" rule.
     */
    public function testEachTokenBecomesItsOwnAndedClause(): void
    {
        $this->query->fetch([new Token('jane'), new Token('smith')], null, 20);

        $this->assertSame(
            [
                ['(match jane)', null, Select::TYPE_CONDITION],
                ['(match smith)', null, Select::TYPE_CONDITION],
            ],
            $this->whereCalls
        );
    }

    /**
     * The type marker is the whole reason a term containing a question mark still searches for what
     * was typed: without it core turns the null value into an empty string and pushes the condition
     * through `quoteInto()`, which is a `str_replace('?', …)` over the finished LIKE pattern.
     */
    public function testAPreBuiltConditionIsMarkedAsOneSoItIsNotRequoted(): void
    {
        $this->query->fetch([new Token('who?')], null, 20);

        $this->assertSame('(match who?)', $this->whereCalls[0][0]);
        $this->assertNull($this->whereCalls[0][1]);
        $this->assertSame(Select::TYPE_CONDITION, $this->whereCalls[0][2]);
    }

    public function testTheWebsiteFilterIsOnlyAddedWhenOneIsAskedFor(): void
    {
        $this->query->fetch([new Token('smith')], 3, 20);

        $this->assertCount(2, $this->whereCalls);
        $this->assertSame('customer.website_id = ?', $this->whereCalls[1][0]);
        $this->assertSame(3, $this->whereCalls[1][1]);
        // A bound value, so this one *does* want core's placeholder substitution.
        $this->assertNull($this->whereCalls[1][2]);
    }

    public function testWebsiteZeroIsAFilterAndNotAnAbsentOne(): void
    {
        $this->query->fetch([new Token('smith')], 0, 20);

        $this->assertCount(2, $this->whereCalls);
        $this->assertSame(0, $this->whereCalls[1][1]);
    }

    /**
     * Exactly one extra row: it is how the caller learns the cap was reached without paying for a
     * second COUNT over the same join.
     */
    public function testTheCapIsOverFetchedByOneRow(): void
    {
        $this->query->fetch([new Token('smith')], null, 20);

        $this->assertSame([21], $this->limitCalls);
    }

    /**
     * A LIMIT without an ORDER BY makes two identical searches disagree about which rows they cut.
     */
    public function testTheResultIsOrderedDeterministically(): void
    {
        $this->query->fetch([new Token('smith')], null, 20);

        $this->assertSame(
            ['customer.lastname ASC', 'customer.firstname ASC', 'customer.entity_id ASC'],
            $this->orderCalls
        );
    }

    public function testTheFetchedRowsAreReturnedUntouched(): void
    {
        $rows = [[CustomerColumns::CUSTOMER_ID => '7', CustomerColumns::EMAIL => 'jane@example.com']];

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->select);
        $connection->expects($this->once())
            ->method('fetchAll')
            ->with($this->select)
            ->willReturn($rows);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => 'pfx_' . $table);

        $query = new CustomerMatchQuery($resourceConnection, $this->conditionBuilder);

        $this->assertSame($rows, $query->fetch([new Token('smith')], null, 20));
    }
}
