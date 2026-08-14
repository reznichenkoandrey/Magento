<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Model;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Api\ReconcileProgressInterface;
use Scr1be\ProductFamilies\Model\CacheInvalidator;
use Scr1be\ProductFamilies\Model\FamilyCapper;
use Scr1be\ProductFamilies\Model\FamilyDefinition;
use Scr1be\ProductFamilies\Model\FamilyDefinitionPool;
use Scr1be\ProductFamilies\Model\FamilyLinkType;
use Scr1be\ProductFamilies\Model\Grouper;
use Scr1be\ProductFamilies\Model\LinkPlan;
use Scr1be\ProductFamilies\Model\LinkPlanner;
use Scr1be\ProductFamilies\Model\PositionResolver;
use Scr1be\ProductFamilies\Model\Reconciler;
use Scr1be\ProductFamilies\Model\ResourceModel\LinkWriter;
use Scr1be\ProductFamilies\Model\ResourceModel\OptionSortOrder;
use Scr1be\ProductFamilies\Model\ResourceModel\ProductScanner;

/**
 * The pipeline end to end, with the two resource models stubbed and everything between them real.
 * Mocking the middle stages here would test the wiring against itself.
 */
class ReconcilerTest extends TestCase
{
    private FamilyDefinitionPool&MockObject $definitionPool;
    private ProductScanner&MockObject $scanner;
    private OptionSortOrder&MockObject $optionSortOrder;
    private LinkWriter&MockObject $writer;
    private CacheInvalidator&MockObject $cacheInvalidator;
    private Reconciler $reconciler;

    protected function setUp(): void
    {
        $this->definitionPool = $this->createMock(FamilyDefinitionPool::class);
        $this->scanner = $this->createMock(ProductScanner::class);
        $this->optionSortOrder = $this->createMock(OptionSortOrder::class);
        $this->writer = $this->createMock(LinkWriter::class);
        $this->cacheInvalidator = $this->createMock(CacheInvalidator::class);

        $positionResolver = new PositionResolver();

        $this->reconciler = new Reconciler(
            $this->definitionPool,
            new FamilyLinkType(),
            $this->scanner,
            $this->optionSortOrder,
            new Grouper(),
            $positionResolver,
            new FamilyCapper($positionResolver),
            new LinkPlanner(),
            $this->writer,
            $this->cacheInvalidator
        );
    }

    public function testARefusedFamilyTouchesNeitherTheCatalogueNorTheTable(): void
    {
        $this->definitionPool->method('getRefusalReason')->willReturn('family "similar" is switched off');
        $this->scanner->expects($this->never())->method('scan');
        $this->writer->expects($this->never())->method('readCurrent');
        $this->writer->expects($this->never())->method('apply');

        $result = $this->reconciler->reconcile('similar');

        $this->assertTrue($result->isRefused());
        $this->assertSame('family "similar" is switched off', $result->getRefusalReason());
        $this->assertSame(FamilyLinkType::LINK_TYPE_SIMILAR, $result->getLinkTypeId());
    }

    public function testBuildsTheLinksAFamilyImpliesAndWritesThem(): void
    {
        $this->givenDefinition();
        $this->givenScan([
            ['entity_id' => 1, 'group_value' => 'hoodie', 'variant_value' => 'black'],
            ['entity_id' => 2, 'group_value' => 'hoodie', 'variant_value' => 'red'],
            ['entity_id' => 3, 'group_value' => 'shoe', 'variant_value' => 'black'],
        ]);
        $this->optionSortOrder->method('getRanking')->willReturn(['black' => 1, 'red' => 2]);
        $this->writer->method('readCurrent')->willReturn([]);

        $applied = null;
        $this->writer->expects($this->once())
            ->method('apply')
            ->willReturnCallback(function (LinkPlan $plan, int $linkTypeId) use (&$applied): void {
                $applied = $plan;
                $this->assertSame(FamilyLinkType::LINK_TYPE_OTHER_COLORS, $linkTypeId);
            });

        $this->cacheInvalidator->expects($this->once())->method('invalidateProducts')->with([1, 2]);

        $result = $this->reconciler->reconcile('other_colors');

        // Only the two-member family survives `dropSingletons`; each member links to the other.
        $this->assertSame(1, $result->getFamilyCount());
        $this->assertSame(2, $result->getMemberCount());
        $this->assertSame(2, $result->getInsertedCount());
        $this->assertSame(
            [
                ['product_id' => 1, 'linked_product_id' => 2, 'position' => 2],
                ['product_id' => 2, 'linked_product_id' => 1, 'position' => 1],
            ],
            $applied?->getInserts()
        );
    }

    public function testADryRunComputesTheSamePlanAndWritesNothing(): void
    {
        $this->givenDefinition();
        $this->givenScan([
            ['entity_id' => 1, 'group_value' => 'hoodie', 'variant_value' => 'black'],
            ['entity_id' => 2, 'group_value' => 'hoodie', 'variant_value' => 'red'],
        ]);
        $this->optionSortOrder->method('getRanking')->willReturn(['black' => 1, 'red' => 2]);
        $this->writer->method('readCurrent')->willReturn([]);

        $this->writer->expects($this->never())->method('apply');
        $this->cacheInvalidator->expects($this->never())->method('invalidateProducts');

        $result = $this->reconciler->reconcile('other_colors', true);

        $this->assertTrue($result->isDryRun());
        $this->assertSame(2, $result->getInsertedCount());
    }

    /**
     * The nightly no-op. Nothing changed, so nothing is written and — the part that matters — the
     * page cache is not touched.
     */
    public function testAnUnchangedCatalogueSkipsTheWriteAndTheInvalidation(): void
    {
        $this->givenDefinition();
        $this->givenScan([
            ['entity_id' => 1, 'group_value' => 'hoodie', 'variant_value' => 'black'],
            ['entity_id' => 2, 'group_value' => 'hoodie', 'variant_value' => 'red'],
        ]);
        $this->optionSortOrder->method('getRanking')->willReturn(['black' => 1, 'red' => 2]);
        $this->writer->method('readCurrent')->willReturn([
            1 => [2 => ['link_id' => 11, 'position' => 2]],
            2 => [1 => ['link_id' => 12, 'position' => 1]],
        ]);

        $this->writer->expects($this->never())->method('apply');
        $this->cacheInvalidator->expects($this->never())->method('invalidateProducts');

        $result = $this->reconciler->reconcile('other_colors');

        $this->assertSame(2, $result->getUnchangedCount());
        $this->assertSame([], $result->getAffectedProductIds());
    }

    /**
     * A multiselect family key puts one pair in two families, each with its own numbering. There is
     * one link row for the pair, so the lower position wins — and it must win regardless of which
     * family the grouper produced first.
     */
    public function testAPairFoundInTwoFamiliesKeepsTheLowerPosition(): void
    {
        $this->givenDefinition(maxMembers: 12);
        // In family "a" product 2 is second; in family "b" it is third, behind product 9.
        $this->givenScan([
            ['entity_id' => 1, 'group_value' => 'a,b', 'variant_value' => 'x1'],
            ['entity_id' => 2, 'group_value' => 'a,b', 'variant_value' => 'x3'],
            ['entity_id' => 9, 'group_value' => 'b', 'variant_value' => 'x2'],
        ]);
        $this->optionSortOrder->method('getRanking')->willReturn(['x1' => 1, 'x2' => 2, 'x3' => 3]);
        $this->writer->method('readCurrent')->willReturn([]);

        $applied = null;
        $this->writer->method('apply')->willReturnCallback(
            static function (LinkPlan $plan) use (&$applied): void {
                $applied = $plan;
            }
        );

        $this->reconciler->reconcile('other_colors');

        $positions = [];
        foreach ($applied?->getInserts() ?? [] as $insert) {
            $positions[$insert['product_id']][$insert['linked_product_id']] = $insert['position'];
        }

        $this->assertSame(2, $positions[1][2], 'family "a" put product 2 at position 2, family "b" at 3');
    }

    public function testTheProgressObserverIsStartedWithTheFamilyCountAndAdvancedOnce(): void
    {
        $this->givenDefinition();
        $this->givenScan([
            ['entity_id' => 1, 'group_value' => 'hoodie', 'variant_value' => 'black'],
            ['entity_id' => 2, 'group_value' => 'hoodie', 'variant_value' => 'red'],
        ]);
        $this->optionSortOrder->method('getRanking')->willReturn([]);
        $this->writer->method('readCurrent')->willReturn([]);

        $progress = $this->createMock(ReconcileProgressInterface::class);
        $progress->expects($this->once())->method('start')->with(1);
        $progress->expects($this->once())->method('advance');
        $progress->expects($this->once())->method('finish');

        $this->reconciler->reconcile('other_colors', true, $progress);
    }

    public function testTheDistinctVariantSwitchReachesTheCapper(): void
    {
        $this->givenDefinition(distinctVariants: true);
        $this->givenScan([
            ['entity_id' => 1, 'group_value' => 'hoodie', 'variant_value' => 'black'],
            ['entity_id' => 2, 'group_value' => 'hoodie', 'variant_value' => 'black'],
            ['entity_id' => 3, 'group_value' => 'hoodie', 'variant_value' => 'red'],
        ]);
        $this->optionSortOrder->method('getRanking')->willReturn(['black' => 1, 'red' => 2]);
        $this->writer->method('readCurrent')->willReturn([]);

        $result = $this->reconciler->reconcile('other_colors', true);

        // Product 2 is dropped as a duplicate black, so the family is 1 ↔ 3 and nothing else.
        $this->assertSame(2, $result->getMemberCount());
        $this->assertSame(2, $result->getInsertedCount());
    }

    private function givenDefinition(
        string $variantAttribute = 'color',
        int $maxMembers = 12,
        bool $distinctVariants = false
    ): void {
        $this->definitionPool->method('getRefusalReason')->willReturn(null);
        $this->definitionPool->method('get')->willReturn(
            new FamilyDefinition(
                'other_colors',
                FamilyLinkType::LINK_TYPE_OTHER_COLORS,
                'style_general',
                $variantAttribute,
                $maxMembers,
                $distinctVariants,
                'Other colours'
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function givenScan(array $rows): void
    {
        $this->scanner->method('scan')->willReturnCallback(
            static function () use ($rows): \Generator {
                yield from $rows;
            }
        );
    }
}
