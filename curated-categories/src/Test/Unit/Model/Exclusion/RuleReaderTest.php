<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model\Exclusion;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Model\Config;
use Scr1be\CuratedCategories\Model\Exclusion\Rule;
use Scr1be\CuratedCategories\Model\Exclusion\RuleReader;
use Scr1be\CuratedCategories\Model\Exclusion\RuleSet;

class RuleReaderTest extends TestCase
{
    private Config&MockObject $config;
    private RuleReader $reader;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->reader = new RuleReader($this->config);
    }

    public function testReadsWellFormedRowsInOrder(): void
    {
        $this->config->method('getExclusionRules')->willReturn([
            '_1700000000_1' => ['attribute' => 'color', 'operator' => 'eq', 'value' => 'Blue'],
            '_1700000000_2' => ['attribute' => 'sku', 'operator' => 'contains', 'value' => 'SAMPLE'],
        ]);
        $this->config->method('getExclusionMatchMode')->willReturn(RuleSet::MATCH_ALL);

        $set = $this->reader->read('new_arrivals');
        $rules = $set->getRules();

        $this->assertCount(2, $rules);
        $this->assertSame('color', $rules[0]->getAttributeCode());
        $this->assertSame(Rule::OPERATOR_CONTAINS, $rules[1]->getOperator());
        $this->assertSame(RuleSet::MATCH_ALL, $set->getMatchMode());
    }

    /**
     * A row that cannot be honoured is dropped, not defaulted. An exclusion rule that quietly turns
     * into a different rule is worse than one that is missing, because the merchant will believe the
     * products are excluded.
     *
     * @dataProvider malformedRowProvider
     */
    public function testDiscardsRowsItCannotHonour(mixed $row): void
    {
        $this->config->method('getExclusionRules')->willReturn(['_1' => $row]);
        $this->config->method('getExclusionMatchMode')->willReturn(RuleSet::MATCH_ANY);

        $this->assertTrue($this->reader->read('new_arrivals')->isEmpty());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedRowProvider(): array
    {
        return [
            'no attribute' => [['attribute' => '', 'operator' => 'eq', 'value' => 'Blue']],
            'whitespace attribute' => [['attribute' => '   ', 'operator' => 'eq', 'value' => 'Blue']],
            'unknown operator' => [['attribute' => 'color', 'operator' => 'regex', 'value' => '.*']],
            'missing operator' => [['attribute' => 'color', 'value' => 'Blue']],
            'not a row at all' => ['a string where a row should be'],
        ];
    }

    /**
     * An empty value is legitimate: "sku is not (blank)" is a real rule, and so is "description
     * contains nothing", which the rule itself then declines to match on.
     */
    public function testKeepsARowWithAnEmptyValue(): void
    {
        $this->config->method('getExclusionRules')->willReturn([
            '_1' => ['attribute' => 'special_price', 'operator' => 'neq'],
        ]);
        $this->config->method('getExclusionMatchMode')->willReturn(RuleSet::MATCH_ANY);

        $rules = $this->reader->read('new_arrivals')->getRules();

        $this->assertCount(1, $rules);
        $this->assertSame('', $rules[0]->getValue());
    }

    /**
     * @dataProvider matchModeProvider
     */
    public function testResolvesTheMatchMode(mixed $stored, string $expected): void
    {
        $this->config->method('getExclusionRules')->willReturn([]);
        $this->config->method('getExclusionMatchMode')->willReturn((string) $stored);

        $this->assertSame($expected, $this->reader->read('new_arrivals')->getMatchMode());
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function matchModeProvider(): array
    {
        return [
            'all' => ['all', RuleSet::MATCH_ALL],
            'all in any case' => ['ALL', RuleSet::MATCH_ALL],
            'any' => ['any', RuleSet::MATCH_ANY],
            // Any excludes more, and keeping a product off a curated page is the recoverable
            // mistake.
            'unset falls back to any' => ['', RuleSet::MATCH_ANY],
            'nonsense falls back to any' => ['sometimes', RuleSet::MATCH_ANY],
        ];
    }
}
