<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Test\Unit\Model\ProductSource;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Model\ProductSource\ManualSkus;

class ManualSkusTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private ManualSkus $source;

    protected function setUp(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $this->source = new ManualSkus($resourceConnection);
    }

    public function testSlidesFollowTheOrderTheMerchandiserTyped(): void
    {
        // The whole point of this source. `WHERE sku IN (…)` answers in storage order, so the order
        // has to be restored afterwards.
        $this->stubLookup([['entity_id' => 7, 'sku' => 'B'], ['entity_id' => 3, 'sku' => 'A']]);

        $this->assertSame([3, 7], $this->source->getProductIds($this->slider('A,B'), 1, 10));
    }

    public function testNewlinesAndCommasAreBothAccepted(): void
    {
        $this->stubLookup([['entity_id' => 1, 'sku' => 'A'], ['entity_id' => 2, 'sku' => 'B']]);

        $this->assertSame([1, 2], $this->source->getProductIds($this->slider("A\nB"), 1, 10));
        $this->assertSame([1, 2], $this->source->getProductIds($this->slider('A, B'), 1, 10));
    }

    public function testSkuMatchingIsCaseInsensitiveBecauseMagentosIs(): void
    {
        // `catalog_product_entity.sku` uses the table's default collation, so an admin who typed
        // `24-mb01` should not get an empty carousel for a product stored as `24-MB01`.
        $this->stubLookup([['entity_id' => 5, 'sku' => '24-MB01']]);

        $this->assertSame([5], $this->source->getProductIds($this->slider('24-mb01'), 1, 10));
    }

    public function testARepeatedSkuRendersOneSlide(): void
    {
        $this->stubLookup([['entity_id' => 5, 'sku' => 'A']]);

        $this->assertSame([5], $this->source->getProductIds($this->slider('A, a, A'), 1, 10));
    }

    public function testAnUnknownSkuIsSkippedAtRenderTimeRatherThanFailing(): void
    {
        // Reported at save time, where somebody can fix it; silently skipped on the storefront,
        // where nobody can.
        $this->stubLookup([['entity_id' => 5, 'sku' => 'A']]);

        $this->assertSame([5], $this->source->getProductIds($this->slider('A,MISSING'), 1, 10));
    }

    public function testTheLimitIsHonoured(): void
    {
        $this->stubLookup([
            ['entity_id' => 1, 'sku' => 'A'],
            ['entity_id' => 2, 'sku' => 'B'],
            ['entity_id' => 3, 'sku' => 'C'],
        ]);

        $this->assertSame([1, 2], $this->source->getProductIds($this->slider('A,B,C'), 1, 2));
    }

    public function testAnEmptyListNeverReachesTheDatabase(): void
    {
        $this->connection->expects($this->never())->method('fetchAll');

        $this->assertSame([], $this->source->getProductIds($this->slider('  '), 1, 10));
    }

    public function testAnEmptyListIsRejectedAtSaveTime(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('at least one SKU');

        $this->source->validateSourceValue('');
    }

    public function testAnAbsurdlyLongListIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('at most 100');

        $this->source->validateSourceValue(implode(',', range(1, 101)));
    }

    public function testMissingSkusAreNamedInTheSaveError(): void
    {
        $this->connection->method('fetchCol')->willReturn(['A']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('GHOST');

        $this->source->validateSourceValue('A,GHOST');
    }

    public function testAListWhereEverySkuExistsSaves(): void
    {
        // Case-insensitively: the stored casing and the typed casing need not agree.
        $this->connection->method('fetchCol')->willReturn(['24-MB01']);

        $this->source->validateSourceValue('24-mb01');

        $this->addToAssertionCount(1);
    }

    /**
     * @param array<int, array{entity_id: int, sku: string}> $rows
     */
    private function stubLookup(array $rows): void
    {
        $this->connection->method('fetchAll')->willReturn($rows);
    }

    private function slider(string $sourceValue): SliderInterface&MockObject
    {
        $slider = $this->createMock(SliderInterface::class);
        $slider->method('getSourceValue')->willReturn($sourceValue);

        return $slider;
    }
}
