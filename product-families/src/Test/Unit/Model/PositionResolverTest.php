<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Model\PositionResolver;

class PositionResolverTest extends TestCase
{
    private PositionResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PositionResolver();
    }

    /**
     * The whole point of the stage: option sort order, not product id order and not the order the
     * scan happened to return. The members below are handed over in the order 'L', 'XS', 'M' with
     * ids that would produce a different sequence on their own.
     */
    public function testOrdersMembersByTheVariantOptionRanking(): void
    {
        $ordered = $this->resolver->resolve(
            [30 => 'L', 10 => 'XS', 20 => 'M'],
            ['XS' => 1, 'S' => 2, 'M' => 3, 'L' => 4]
        );

        $this->assertSame([10, 20, 30], array_column($ordered, 'id'));
        $this->assertSame([1, 2, 3], array_column($ordered, 'position'));
    }

    public function testMembersWithNoRankedOptionSortAfterEveryRankedMember(): void
    {
        $ordered = $this->resolver->resolve(
            [1 => 'unknown', 2 => 'M', 3 => ''],
            ['M' => 1]
        );

        $this->assertSame([2, 1, 3], array_column($ordered, 'id'));
    }

    /**
     * Without this the family's contents would depend on PHP's sort implementation, and a product
     * page would show a different order after an unrelated reconcile — churn the diff would then
     * happily write to the database.
     */
    public function testTiesFallBackToProductIdSoRunsAreRepeatable(): void
    {
        $ordered = $this->resolver->resolve(
            [93 => 'M', 91 => 'M', 92 => 'M'],
            ['M' => 1]
        );

        $this->assertSame([91, 92, 93], array_column($ordered, 'id'));
        $this->assertSame([1, 2, 3], array_column($ordered, 'position'));
    }

    public function testPositionsStartAtOneAndAreContiguous(): void
    {
        $ordered = $this->resolver->resolve([5 => 'a', 6 => 'b'], ['a' => 40, 'b' => 90]);

        $this->assertSame([1, 2], array_column($ordered, 'position'));
    }

    public function testCarriesTheVariantValueThroughForTheCapperAndTheChipLabel(): void
    {
        $ordered = $this->resolver->resolve([5 => 'red'], ['red' => 1]);

        $this->assertSame('red', $ordered[0]['variant']);
    }

    public function testRenumberingAnAlreadyOrderedListClosesTheGaps(): void
    {
        $numbered = $this->resolver->number([
            ['id' => 4, 'variant' => 'a'],
            ['id' => 9, 'variant' => 'b'],
        ]);

        $this->assertSame(
            [
                ['id' => 4, 'position' => 1, 'variant' => 'a'],
                ['id' => 9, 'position' => 2, 'variant' => 'b'],
            ],
            $numbered
        );
    }

    public function testAnEmptyFamilyResolvesToAnEmptyList(): void
    {
        $this->assertSame([], $this->resolver->resolve([], ['a' => 1]));
    }
}
