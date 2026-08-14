<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Model\LinkPlanner;

class LinkPlannerTest extends TestCase
{
    private LinkPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new LinkPlanner();
    }

    public function testAFirstRunAgainstAnEmptyTableIsAllInserts(): void
    {
        $plan = $this->planner->plan([1 => [2 => 1, 3 => 2]], []);

        $this->assertSame(
            [
                ['product_id' => 1, 'linked_product_id' => 2, 'position' => 1],
                ['product_id' => 1, 'linked_product_id' => 3, 'position' => 2],
            ],
            $plan->getInserts()
        );
        $this->assertSame([], $plan->getUpdates());
        $this->assertSame([], $plan->getDeletes());
        $this->assertSame([1], $plan->getAffectedProductIds());
    }

    /**
     * The property the nightly schedule depends on: a second run over unchanged data writes nothing
     * and invalidates nothing. Without it the module would evict the entire catalogue's page cache
     * every night for no reason.
     */
    public function testAnUnchangedCatalogueProducesAnEmptyPlan(): void
    {
        $plan = $this->planner->plan(
            [1 => [2 => 1]],
            [1 => [2 => ['link_id' => 55, 'position' => 1]]]
        );

        $this->assertTrue($plan->isEmpty());
        $this->assertSame(1, $plan->getUnchangedCount());
        $this->assertSame([], $plan->getAffectedProductIds());
    }

    /**
     * A re-rank is one integer written to `catalog_product_link_attribute_int`, addressed by the
     * link id that already exists — not a delete followed by an insert that would burn a new
     * `link_id` and put the same pair through the diff twice.
     */
    public function testAMovedMemberBecomesAnUpdateAgainstTheExistingLinkId(): void
    {
        $plan = $this->planner->plan(
            [1 => [2 => 3]],
            [1 => [2 => ['link_id' => 55, 'position' => 1]]]
        );

        $this->assertSame([], $plan->getInserts());
        $this->assertSame([['link_id' => 55, 'position' => 3]], $plan->getUpdates());
        $this->assertSame([], $plan->getDeletes());
    }

    public function testLinksTheCatalogueNoLongerWantsBecomeDeletes(): void
    {
        $plan = $this->planner->plan(
            [1 => [2 => 1]],
            [1 => [2 => ['link_id' => 55, 'position' => 1], 3 => ['link_id' => 56, 'position' => 2]]]
        );

        $this->assertSame([56], $plan->getDeletes());
        $this->assertSame([1], $plan->getAffectedProductIds());
    }

    /**
     * A product that left the catalogue's families entirely has no entry in the desired map at all,
     * so the removal has to come from walking the current state rather than the desired one.
     */
    public function testAProductThatLeftEveryFamilyLosesItsWholeRow(): void
    {
        $plan = $this->planner->plan(
            [],
            [9 => [1 => ['link_id' => 70, 'position' => 1], 2 => ['link_id' => 71, 'position' => 2]]]
        );

        $this->assertSame([70, 71], $plan->getDeletes());
        $this->assertSame([9], $plan->getAffectedProductIds());
    }

    public function testAffectedProductsAreDeduplicatedAndSorted(): void
    {
        $plan = $this->planner->plan(
            [7 => [1 => 1, 2 => 2], 3 => [1 => 1]],
            [7 => [1 => ['link_id' => 10, 'position' => 9]]]
        );

        $this->assertSame([3, 7], $plan->getAffectedProductIds());
    }

    public function testUnchangedLinksDoNotMarkTheirProductAffected(): void
    {
        $plan = $this->planner->plan(
            [1 => [2 => 1], 4 => [5 => 1]],
            [1 => [2 => ['link_id' => 55, 'position' => 1]]]
        );

        $this->assertSame([4], $plan->getAffectedProductIds());
        $this->assertSame(1, $plan->getUnchangedCount());
    }

    /**
     * Ids arriving from `fetchAll()` are strings. Comparing a string key against an int one with a
     * loose `isset` works, but the plan itself must be typed or the writer would bind strings.
     */
    public function testNormalisesIdentifiersThatArrivedAsStrings(): void
    {
        $plan = $this->planner->plan(
            ['1' => ['2' => '4']],
            ['1' => ['2' => ['link_id' => '55', 'position' => '1']]]
        );

        $this->assertSame([['link_id' => 55, 'position' => 4]], $plan->getUpdates());
    }

    public function testDeletesAreSortedSoTwoIdenticalRunsProduceIdenticalPlans(): void
    {
        $plan = $this->planner->plan(
            [],
            [
                2 => [1 => ['link_id' => 90, 'position' => 1]],
                1 => [1 => ['link_id' => 12, 'position' => 1]],
            ]
        );

        $this->assertSame([12, 90], $plan->getDeletes());
    }
}
