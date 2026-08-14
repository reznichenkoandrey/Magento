<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model\Exclusion;

use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Model\Exclusion\Rule;

class RuleTest extends TestCase
{
    /**
     * The admin types strings; EAV hands back option ids and numeric strings. A strict comparison
     * here would make every numeric rule match nothing — the failure mode that leaves a merchant
     * convinced products are excluded when they are not.
     *
     * @dataProvider comparisonProvider
     */
    public function testComparisons(string $operator, string $ruleValue, mixed $attributeValue, bool $expected): void
    {
        $rule = new Rule('any_code', $operator, $ruleValue);

        $this->assertSame($expected, $rule->matches($attributeValue));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: mixed, 3: bool}>
     */
    public static function comparisonProvider(): array
    {
        return [
            'eq matches an identical string' => [Rule::OPERATOR_EQ, 'Blue', 'Blue', true],
            'eq is not case-insensitive' => [Rule::OPERATOR_EQ, 'blue', 'Blue', false],
            'eq ignores surrounding whitespace' => [Rule::OPERATOR_EQ, ' Blue ', 'Blue', true],
            'eq compares an option id numerically' => [Rule::OPERATOR_EQ, '42', 42, true],
            'eq compares a decimal numerically' => [Rule::OPERATOR_EQ, '10', '10.0000', true],
            'eq never matches a missing value' => [Rule::OPERATOR_EQ, '', null, false],

            'neq is the inverse of eq' => [Rule::OPERATOR_NEQ, 'Blue', 'Red', true],
            'neq matches a missing value' => [Rule::OPERATOR_NEQ, 'Blue', null, true],
            'neq matches an empty value' => [Rule::OPERATOR_NEQ, 'Blue', '', true],

            'gt compares numerically' => [Rule::OPERATOR_GT, '100', '150.5000', true],
            'gt is false at the boundary' => [Rule::OPERATOR_GT, '100', '100', false],
            'gt refuses a non-numeric attribute' => [Rule::OPERATOR_GT, '100', 'Blue', false],
            'gt refuses a non-numeric rule value' => [Rule::OPERATOR_GT, 'soon', '100', false],
            'gt never matches a missing value' => [Rule::OPERATOR_GT, '100', null, false],

            'lt compares numerically' => [Rule::OPERATOR_LT, '100', '99', true],
            'lt refuses a non-numeric attribute' => [Rule::OPERATOR_LT, '100', '', false],

            'in splits on commas' => [Rule::OPERATOR_IN, 'Blue, Red , Green', 'Red', true],
            'in tolerates numeric ids' => [Rule::OPERATOR_IN, '11,12,13', 12, true],
            'in misses what is not listed' => [Rule::OPERATOR_IN, 'Blue,Red', 'Green', false],
            'in never matches a missing value' => [Rule::OPERATOR_IN, 'Blue', null, false],

            'nin is the inverse of in' => [Rule::OPERATOR_NIN, 'Blue,Red', 'Green', true],
            'nin misses what is listed' => [Rule::OPERATOR_NIN, 'Blue,Red', 'Blue', false],
            'nin matches a missing value' => [Rule::OPERATOR_NIN, 'Blue', null, true],

            'contains is case-insensitive' => [Rule::OPERATOR_CONTAINS, 'sample', 'MH01-SAMPLE', true],
            'contains misses a substring that is not there' => [Rule::OPERATOR_CONTAINS, 'sample', 'MH01', false],
            'contains with an empty needle matches nothing' => [Rule::OPERATOR_CONTAINS, '  ', 'MH01', false],
            'contains never matches a missing value' => [Rule::OPERATOR_CONTAINS, 'x', null, false],
        ];
    }

    /**
     * An operator that is not one of the seven cannot come from the form, but it can come from a
     * hand-edited `core_config_data`. Matching nothing is the reading that cannot silently exclude a
     * product the merchant never banned.
     */
    public function testAnUnknownOperatorMatchesNothing(): void
    {
        $rule = new Rule('color', 'regex', '.*');

        $this->assertFalse($rule->matches('anything'));
    }

    public function testExposesItsOwnParts(): void
    {
        $rule = new Rule('color', Rule::OPERATOR_EQ, 'Blue');

        $this->assertSame('color', $rule->getAttributeCode());
        $this->assertSame(Rule::OPERATOR_EQ, $rule->getOperator());
        $this->assertSame('Blue', $rule->getValue());
    }
}
