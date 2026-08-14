<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Model\Config;
use Scr1be\CuratedCategories\Model\CurationEngine;
use Scr1be\CuratedCategories\Model\CurationTarget;
use Scr1be\CuratedCategories\Model\FloorGuard;
use Scr1be\CuratedCategories\Model\ResourceModel\CategoryMembership;

class CurationEngineTest extends TestCase
{
    private const CATEGORY_ID = 42;

    private CategoryMembership&MockObject $membership;
    private Config&MockObject $config;
    private CurationEngine $engine;

    protected function setUp(): void
    {
        $this->membership = $this->createMock(CategoryMembership::class);
        $this->config = $this->createMock(Config::class);

        // The floor guard is real: its behaviour is the engine's behaviour on half of these cases,
        // and a mock would only be able to prove the engine called something.
        $this->engine = new CurationEngine($this->membership, new FloorGuard(), $this->config);

        $this->membership->method('filterExistingProducts')
            ->willReturnCallback(static fn (array $ids): array => $ids);
    }

    public function testReconcileSplitsMembershipIntoAddedRemovedAndUnchanged(): void
    {
        $this->membership->method('getMembership')->willReturn([10 => 1, 20 => 2, 30 => 3]);

        $this->membership->expects($this->once())
            ->method('upsert')
            ->with(self::CATEGORY_ID, [20 => 1, 40 => 2, 10 => 3]);
        $this->membership->expects($this->once())
            ->method('remove')
            ->with(self::CATEGORY_ID, [30]);

        $result = $this->engine->reconcileAll($this->target(1), [20, 40, 10]);

        $this->assertSame([40], $result->getAdded());
        $this->assertSame([30], $result->getRemoved());
        $this->assertSame([20, 10], $result->getUnchanged());
        $this->assertSame([], $result->getRetainedByFloor());
        $this->assertFalse($result->isRefused());
        $this->assertFalse($result->isDryRun());
    }

    /**
     * The source's ranking becomes the position, one-based and gapless, and the products the floor
     * kept back go after the whole ranked block — never interleaved by their old position, which
     * would let a stale member outrank today's number one.
     */
    public function testFloorRetainedProductsArePositionedAfterTheRankedBlock(): void
    {
        $this->membership->method('getMembership')->willReturn([10 => 1, 20 => 2, 30 => 3]);

        $this->membership->expects($this->once())
            ->method('upsert')
            ->with(self::CATEGORY_ID, [30 => 1, 10 => 2, 20 => 3]);
        $this->membership->expects($this->once())->method('remove')->with(self::CATEGORY_ID, []);

        $result = $this->engine->reconcileAll($this->target(3), [30]);

        $this->assertSame([], $result->getAdded());
        $this->assertSame([], $result->getRemoved());
        $this->assertSame([10, 20], $result->getRetainedByFloor());
        // Floor-retained members are still members, so they belong in `unchanged` too — the three
        // buckets have to partition the outcome or a caller cannot reconcile the counts.
        $this->assertSame([30, 10, 20], $result->getUnchanged());
    }

    public function testRefusesAnEmptySourceAgainstANonEmptyCategory(): void
    {
        $this->membership->method('getMembership')->willReturn([10 => 1]);
        $this->config->method('isEmptySourceAllowed')->willReturn(false);

        $this->membership->expects($this->never())->method('upsert');
        $this->membership->expects($this->never())->method('remove');

        $result = $this->engine->reconcileAll($this->target(1), []);

        $this->assertTrue($result->isRefused());
        $this->assertStringContainsString('1 member', (string) $result->getRefusalReason());
    }

    /**
     * An empty source against an empty category is not a misconfiguration, it is a quiet day. The
     * guard exists to stop a category being emptied, and there is nothing here to empty.
     */
    public function testDoesNotRefuseAnEmptySourceAgainstAnEmptyCategory(): void
    {
        $this->membership->method('getMembership')->willReturn([]);
        $this->config->method('isEmptySourceAllowed')->willReturn(false);

        $result = $this->engine->reconcileAll($this->target(4), []);

        $this->assertFalse($result->isRefused());
        $this->assertSame([], $result->getAdded());
        $this->assertSame([], $result->getRemoved());
    }

    /**
     * Permission to empty the category has to disable the floor as well, or four products come
     * straight back and the setting does nothing.
     */
    public function testAnAllowedEmptySourceClearsTheCategoryAndBypassesTheFloor(): void
    {
        $this->membership->method('getMembership')->willReturn([10 => 1, 20 => 2]);
        $this->config->method('isEmptySourceAllowed')->willReturn(true);

        $this->membership->expects($this->once())->method('remove')->with(self::CATEGORY_ID, [10, 20]);

        $result = $this->engine->reconcileAll($this->target(4), []);

        $this->assertFalse($result->isRefused());
        $this->assertSame([10, 20], $result->getRemoved());
        $this->assertSame([], $result->getRetainedByFloor());
    }

    public function testRefusesWhenEveryProductTheSourceNamedHasBeenDeleted(): void
    {
        $membership = $this->createMock(CategoryMembership::class);
        $membership->method('getMembership')->willReturn([10 => 1, 20 => 2]);
        $membership->method('filterExistingProducts')->willReturn([]);
        $membership->expects($this->never())->method('remove');

        $this->config->method('isEmptySourceAllowed')->willReturn(false);

        $engine = new CurationEngine($membership, new FloorGuard(), $this->config);
        $result = $engine->reconcileAll($this->target(1), [777, 888]);

        $this->assertTrue($result->isRefused());
        $this->assertStringContainsString('deleted', (string) $result->getRefusalReason());
    }

    public function testDryRunComputesThePlanWithoutWriting(): void
    {
        $this->membership->method('getMembership')->willReturn([10 => 1, 20 => 2]);

        $this->membership->expects($this->never())->method('upsert');
        $this->membership->expects($this->never())->method('remove');

        $result = $this->engine->reconcileAll($this->target(1), [20, 30], true);

        $this->assertTrue($result->isDryRun());
        $this->assertSame([30], $result->getAdded());
        $this->assertSame([10], $result->getRemoved());
    }

    public function testRefusesEveryVerbWhenNoCategoryIsConfigured(): void
    {
        $target = new CurationTarget(0, 1, 'bestsellers');

        $this->membership->expects($this->never())->method('getMembership');

        $this->assertTrue($this->engine->reconcileAll($target, [1])->isRefused());
        $this->assertTrue($this->engine->add($target, [1])->isRefused());
        $this->assertTrue($this->engine->remove($target, [1])->isRefused());
    }

    public function testAddAppendsAfterTheCurrentTailAndLeavesExistingPositionsAlone(): void
    {
        $this->membership->method('getMembership')->willReturn([10 => 4, 20 => 9]);

        $this->membership->expects($this->once())
            ->method('upsert')
            ->with(self::CATEGORY_ID, [30 => 10, 40 => 11]);
        $this->membership->expects($this->never())->method('remove');

        $result = $this->engine->add($this->target(1), [30, 20, 40]);

        $this->assertSame([30, 40], $result->getAdded());
        $this->assertSame([20], $result->getUnchanged());
    }

    public function testAddIsANoOpWhenEveryProductIsAlreadyAMember(): void
    {
        $this->membership->method('getMembership')->willReturn([10 => 1]);
        $this->membership->expects($this->never())->method('upsert');

        $result = $this->engine->add($this->target(1), [10]);

        $this->assertSame([], $result->getAdded());
        $this->assertSame([10], $result->getUnchanged());
    }

    public function testRemoveObeysTheFloorAndDropsTheHighestPositionsFirst(): void
    {
        $this->membership->method('getMembership')->willReturn([10 => 1, 20 => 2, 30 => 3]);

        $this->membership->expects($this->once())->method('remove')->with(self::CATEGORY_ID, [30]);

        $result = $this->engine->remove($this->target(2), [10, 20, 30]);

        $this->assertSame([30], $result->getRemoved());
        $this->assertSame([10, 20], $result->getRetainedByFloor());
        $this->assertSame([10, 20], $result->getUnchanged());
    }

    public function testRemoveIgnoresProductsThatAreNotMembers(): void
    {
        $this->membership->method('getMembership')->willReturn([10 => 1]);
        $this->membership->expects($this->never())->method('remove');

        $result = $this->engine->remove($this->target(1), [999]);

        $this->assertSame([], $result->getRemoved());
        $this->assertSame([10], $result->getUnchanged());
    }

    /**
     * Sources hand over whatever their query produced. Duplicates would write the same product twice
     * with two different positions — the second silently winning — and a zero or a numeric string is
     * what a fetchCol from a nullable column looks like.
     */
    public function testNormalisesDuplicatesStringsAndNonPositiveIds(): void
    {
        $this->membership->method('getMembership')->willReturn([]);

        $this->membership->expects($this->once())
            ->method('upsert')
            ->with(self::CATEGORY_ID, [10 => 1, 20 => 2]);

        $result = $this->engine->reconcileAll($this->target(1), ['10', 10, 0, -5, '20']);

        $this->assertSame([10, 20], $result->getAdded());
    }

    private function target(int $floor): CurationTarget
    {
        return new CurationTarget(self::CATEGORY_ID, $floor, 'bestsellers');
    }
}
