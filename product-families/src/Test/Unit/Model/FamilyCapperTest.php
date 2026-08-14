<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Model\FamilyCapper;
use Scr1be\ProductFamilies\Model\PositionResolver;

class FamilyCapperTest extends TestCase
{
    private FamilyCapper $capper;

    protected function setUp(): void
    {
        // Against the real resolver, not a mock: the capper's contract is that the list it hands
        // back is renumbered, and a mock would let a regression through.
        $this->capper = new FamilyCapper(new PositionResolver());
    }

    public function testCollapsesRepeatedVariantValuesToTheFirstMember(): void
    {
        $kept = $this->capper->collapseDuplicateVariants([
            ['id' => 1, 'position' => 1, 'variant' => 'black'],
            ['id' => 2, 'position' => 2, 'variant' => 'black'],
            ['id' => 3, 'position' => 3, 'variant' => 'red'],
        ]);

        $this->assertSame([1, 3], array_column($kept, 'id'));
        $this->assertSame([1, 2], array_column($kept, 'position'), 'positions are closed up');
    }

    /**
     * The trap this guards. An empty variant value is "unknown", not "the same unknown as the other
     * one" — and on a catalogue where the attribute is unset on most products, folding them together
     * would delete the whole family down to a single chip.
     */
    public function testNeverCollapsesMembersThatHaveNoVariantValue(): void
    {
        $kept = $this->capper->collapseDuplicateVariants([
            ['id' => 1, 'position' => 1, 'variant' => ''],
            ['id' => 2, 'position' => 2, 'variant' => ''],
            ['id' => 3, 'position' => 3, 'variant' => ''],
        ]);

        $this->assertSame([1, 2, 3], array_column($kept, 'id'));
    }

    public function testEveryMemberOfASmallFamilyLinksToEveryOther(): void
    {
        $links = $this->capper->buildLinks($this->family(3), 12);

        $this->assertSame(
            [
                1 => [2 => 2, 3 => 3],
                2 => [1 => 1, 3 => 3],
                3 => [1 => 1, 2 => 2],
            ],
            $links
        );
    }

    /**
     * The reason the cap is per source product rather than per family. With a "keep the first
     * twelve members" reading, ids 13 and up would carry no links at all; here every member keeps a
     * full row.
     */
    public function testEveryMemberOfALargeFamilyStillGetsARow(): void
    {
        $links = $this->capper->buildLinks($this->family(40), 4);

        $this->assertCount(40, $links);
        foreach ($links as $row) {
            $this->assertCount(4, $row);
        }
    }

    public function testTheRowHoldsTheMembersNearestInFamilyOrder(): void
    {
        $links = $this->capper->buildLinks($this->family(9), 4);

        // Member 5 sits in the middle: its neighbours are 4 and 6, then 3 and 7.
        $this->assertSame([3, 4, 6, 7], array_keys($links[5]));
    }

    /**
     * A member two places below and two above are equidistant. Resolving towards the lower position
     * keeps the row leaning to the start of the family instead of depending on iteration order.
     */
    public function testEquidistantNeighboursResolveTowardsTheLowerPosition(): void
    {
        $links = $this->capper->buildLinks($this->family(5), 1);

        $this->assertSame([2 => 2], $links[3], 'position 2 wins over the equally close position 4');
    }

    /**
     * What is written as the link position is the *linked* member's position in the family, not its
     * rank inside the window — otherwise the same product would render at a different place in
     * every row it appears in.
     */
    public function testTheStoredPositionIsTheMembersFamilyPosition(): void
    {
        $links = $this->capper->buildLinks($this->family(9), 2);

        // Member 8's window is 7 and 9, and the positions written are 7 and 9 — not 1 and 2.
        $this->assertSame([7 => 7, 9 => 9], $links[8]);
    }

    public function testAFamilyOfOneProducesNothing(): void
    {
        $this->assertSame([], $this->capper->buildLinks($this->family(1), 12));
    }

    public function testACapBelowOneProducesNothing(): void
    {
        $this->assertSame([], $this->capper->buildLinks($this->family(5), 0));
    }

    /**
     * @return array<int, array{id: int, position: int, variant: string}>
     */
    private function family(int $size): array
    {
        $family = [];
        for ($i = 1; $i <= $size; $i++) {
            $family[] = ['id' => $i, 'position' => $i, 'variant' => 'v' . $i];
        }

        return $family;
    }
}
