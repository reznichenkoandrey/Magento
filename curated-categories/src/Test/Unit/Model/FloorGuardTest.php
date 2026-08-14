<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Model\FloorGuard;

class FloorGuardTest extends TestCase
{
    private FloorGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new FloorGuard();
    }

    public function testRemovesEverythingWhenTheSurvivorsAlreadyMeetTheFloor(): void
    {
        [$removed, $retained] = $this->guard->apply([7, 8], [7 => 1, 8 => 2, 9 => 3, 10 => 4], 2, 2);

        $this->assertSame([7, 8], $removed);
        $this->assertSame([], $retained);
    }

    public function testKeepsBackExactlyTheDeficitAndNoMore(): void
    {
        // Four members, three of them going, floor of three: two have to stay behind.
        [$removed, $retained] = $this->guard->apply(
            [11, 12, 13],
            [10 => 1, 11 => 2, 12 => 3, 13 => 4],
            1,
            3
        );

        $this->assertSame([11, 12], $retained);
        $this->assertSame([13], $removed);
    }

    /**
     * The whole contract in one case: the survivors are the lowest positions, which is the same
     * statement as "removal starts at the highest position". The candidates are handed over in an
     * order that has nothing to do with position, so a guard that trusted the input order would
     * retain 40 and 30 here.
     */
    public function testRetainsTheLowestPositionsRegardlessOfCandidateOrder(): void
    {
        [$removed, $retained] = $this->guard->apply(
            [40, 10, 30, 20],
            [10 => 4, 20 => 3, 30 => 2, 40 => 1],
            0,
            2
        );

        $this->assertSame([40, 30], $retained);
        $this->assertSame([20, 10], $removed);
    }

    /**
     * Hand-assigned members all sit at position 0. Without a deterministic tie-break the retained
     * pair would depend on the sort implementation, and a category's contents would flap between
     * identical runs — the opposite of what an SEO floor is for.
     */
    public function testBreaksPositionTiesOnProductIdSoRunsAreRepeatable(): void
    {
        [$removed, $retained] = $this->guard->apply(
            [93, 91, 92],
            [91 => 0, 92 => 0, 93 => 0],
            0,
            2
        );

        $this->assertSame([91, 92], $retained);
        $this->assertSame([93], $removed);
    }

    public function testKeepsEverythingWhenTheFloorExceedsWhatIsAvailable(): void
    {
        [$removed, $retained] = $this->guard->apply([5, 6], [5 => 1, 6 => 2], 0, 10);

        $this->assertSame([], $removed);
        $this->assertSame([5, 6], $retained);
    }

    public function testDoesNothingWithNoCandidates(): void
    {
        [$removed, $retained] = $this->guard->apply([], [5 => 1], 1, 10);

        $this->assertSame([], $removed);
        $this->assertSame([], $retained);
    }

    /**
     * A floor of zero is what the engine passes when the merchant has explicitly allowed an empty
     * source to clear the category. The guard has to step aside completely, or that permission is
     * worth nothing.
     */
    public function testStepsAsideEntirelyWhenTheFloorIsZero(): void
    {
        [$removed, $retained] = $this->guard->apply([1, 2, 3], [1 => 1, 2 => 2, 3 => 3], 0, 0);

        $this->assertSame([1, 2, 3], $removed);
        $this->assertSame([], $retained);
    }

    /**
     * A candidate the caller named that is not in the membership map has no position. Treating it as
     * zero puts it at the front of the retention queue, which is the conservative reading — but the
     * important part is that it does not crash on the missing key.
     */
    public function testTreatsAnUnknownPositionAsZero(): void
    {
        [$removed, $retained] = $this->guard->apply([50, 60], [60 => 5], 0, 1);

        $this->assertSame([50], $retained);
        $this->assertSame([60], $removed);
    }
}
