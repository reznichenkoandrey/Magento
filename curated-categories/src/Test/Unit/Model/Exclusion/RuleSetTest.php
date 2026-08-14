<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model\Exclusion;

use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Model\Exclusion\Rule;
use Scr1be\CuratedCategories\Model\Exclusion\RuleSet;

class RuleSetTest extends TestCase
{
    public function testAnyExcludesOnTheFirstMatch(): void
    {
        $set = new RuleSet(
            [
                new Rule('color', Rule::OPERATOR_EQ, 'Blue'),
                new Rule('sku', Rule::OPERATOR_CONTAINS, 'SAMPLE'),
            ],
            RuleSet::MATCH_ANY
        );

        $this->assertTrue($set->excludes(['color' => 'Red', 'sku' => 'MH01-SAMPLE']));
        $this->assertTrue($set->excludes(['color' => 'Blue', 'sku' => 'MH01']));
        $this->assertFalse($set->excludes(['color' => 'Red', 'sku' => 'MH01']));
    }

    public function testAllExcludesOnlyWhenEveryRuleMatches(): void
    {
        $set = new RuleSet(
            [
                new Rule('color', Rule::OPERATOR_EQ, 'Blue'),
                new Rule('price', Rule::OPERATOR_LT, '20'),
            ],
            RuleSet::MATCH_ALL
        );

        $this->assertTrue($set->excludes(['color' => 'Blue', 'price' => '15.0000']));
        $this->assertFalse($set->excludes(['color' => 'Blue', 'price' => '99.0000']));
        $this->assertFalse($set->excludes(['color' => 'Red', 'price' => '15.0000']));
    }

    /**
     * The vacuous-truth reading of "all rules match" would exclude every product the moment the
     * merchant saved an empty form — which is the exact misconfiguration this module has a guard
     * for, and a much better one to simply not have.
     */
    public function testAnEmptySetExcludesNothingUnderEitherMode(): void
    {
        $this->assertFalse((new RuleSet([], RuleSet::MATCH_ALL))->excludes(['color' => 'Blue']));
        $this->assertFalse((new RuleSet([], RuleSet::MATCH_ANY))->excludes(['color' => 'Blue']));
    }

    /**
     * A rule naming an attribute the product does not carry is evaluated against null, which is what
     * lets `is not` mean "does not have this value" rather than being skipped.
     */
    public function testAMissingAttributeIsEvaluatedAsNull(): void
    {
        $set = new RuleSet([new Rule('gift_message_available', Rule::OPERATOR_NEQ, '1')], RuleSet::MATCH_ANY);

        $this->assertTrue($set->excludes([]));
    }

    public function testCollectsTheAttributeCodesItNeedsWithoutDuplicates(): void
    {
        $set = new RuleSet(
            [
                new Rule('color', Rule::OPERATOR_EQ, 'Blue'),
                new Rule('color', Rule::OPERATOR_NEQ, 'Red'),
                new Rule('sku', Rule::OPERATOR_CONTAINS, 'X'),
            ],
            RuleSet::MATCH_ANY
        );

        $this->assertSame(['color', 'sku'], $set->getAttributeCodes());
        $this->assertFalse($set->isEmpty());
        $this->assertSame(RuleSet::MATCH_ANY, $set->getMatchMode());
        $this->assertCount(3, $set->getRules());
    }
}
