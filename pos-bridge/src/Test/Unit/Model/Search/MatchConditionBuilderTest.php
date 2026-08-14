<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Test\Unit\Model\Search;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Helper as DbHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\PosBridge\Model\Search\MatchConditionBuilder;
use Scr1be\PosBridge\Model\Search\Token;

/**
 * The seam against the database layer. The adapter is stubbed with predictable quoting so the test
 * asserts the *shape* the builder produces — which columns are consulted, how many alternatives a
 * token yields, and that the escaping call is delegated rather than improvised.
 */
class MatchConditionBuilderTest extends TestCase
{
    private DbHelper&MockObject $dbHelper;
    private AdapterInterface&MockObject $connection;
    private MatchConditionBuilder $builder;

    protected function setUp(): void
    {
        $this->dbHelper = $this->createMock(DbHelper::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->builder = new MatchConditionBuilder($this->dbHelper);

        $this->connection->method('quoteIdentifier')
            ->willReturnCallback(static fn (string $identifier): string => '`' . $identifier . '`');
        $this->connection->method('quote')
            ->willReturnCallback(static fn (string $value): string => "'" . $value . "'");
    }

    public function testATextTokenConsultsEveryTextColumnAndNothingElse(): void
    {
        $this->expectContainsEscaping('smith');

        $condition = $this->builder->forToken($this->connection, new Token('smith'));

        $this->assertSame(
            count(MatchConditionBuilder::TEXT_COLUMNS),
            substr_count($condition, ' LIKE '),
            'a term with too few digits must not reach the telephone expression'
        );
        $this->assertStringNotContainsString('REPLACE(', $condition);

        foreach (MatchConditionBuilder::TEXT_COLUMNS as $column) {
            $this->assertStringContainsString('`' . $column . "` LIKE '%smith%'", $condition);
        }
    }

    /**
     * The alternatives are OR-ed and the whole token is parenthesised. Without the parentheses the
     * caller's AND between tokens would bind tighter than the ORs inside one, and a two-word search
     * would quietly return everything that matched the last word.
     */
    public function testTheTokenIsOneParenthesisedGroupOfAlternatives(): void
    {
        $this->expectContainsEscaping('smith');

        $condition = $this->builder->forToken($this->connection, new Token('smith'));

        $this->assertStringStartsWith('(', $condition);
        $this->assertStringEndsWith(')', $condition);
        $this->assertSame(
            count(MatchConditionBuilder::TEXT_COLUMNS) - 1,
            substr_count($condition, ' OR ')
        );
    }

    public function testALongEnoughDigitRunAddsThePhoneAlternative(): void
    {
        $this->dbHelper->method('escapeLikeValue')
            ->willReturnCallback(static fn (string $value): string => '%' . $value . '%');

        $condition = $this->builder->forToken($this->connection, new Token('(555) 229'));

        $this->assertSame(
            count(MatchConditionBuilder::TEXT_COLUMNS) + 1,
            substr_count($condition, ' LIKE ')
        );
        // The text columns see the term as typed; the phone alternative sees it stripped.
        $this->assertStringContainsString("'%(555) 229%'", $condition);
        $this->assertStringContainsString("'%555229%'", $condition);
    }

    public function testTheTelephoneExpressionStripsEverySeparatorOnce(): void
    {
        $expression = $this->builder->digitsOnlyTelephone($this->connection);

        $this->assertSame(
            count(MatchConditionBuilder::PHONE_SEPARATORS),
            substr_count($expression, 'REPLACE(')
        );
        $this->assertStringContainsString('`billing.telephone`', $expression);

        foreach (MatchConditionBuilder::PHONE_SEPARATORS as $separator) {
            $this->assertStringContainsString("'" . $separator . "', ''", $expression);
        }
    }

    /**
     * Escaping is core's job. If this delegation is ever replaced by string concatenation, a shopper
     * searched for as `%` matches every customer in the installation.
     */
    public function testEscapingIsDelegatedToCoreAsAContainsMatch(): void
    {
        $this->dbHelper->expects($this->once())
            ->method('escapeLikeValue')
            ->with('50%', ['position' => 'any'])
            ->willReturn('%50\%%');

        $condition = $this->builder->forToken($this->connection, new Token('50%'));

        $this->assertStringContainsString("'%50\\%%'", $condition);
    }

    private function expectContainsEscaping(string $term): void
    {
        $this->dbHelper->method('escapeLikeValue')
            ->with($term, ['position' => 'any'])
            ->willReturn('%' . $term . '%');
    }
}
