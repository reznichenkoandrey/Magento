<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Test\Unit\Plugin\Catalog\ResourceModel\Category;

use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Framework\DataObject;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CategoryCascade\Model\CascadeLog;
use Scr1be\CategoryCascade\Model\Config;
use Scr1be\CategoryCascade\Model\ResourceModel\IndexedProductCount;
use Scr1be\CategoryCascade\Plugin\Catalog\ResourceModel\Category\ProductCountFromIndex;

class ProductCountFromIndexTest extends TestCase
{
    private Config&MockObject $config;
    private IndexedProductCount&MockObject $indexedCount;
    private IndexerRegistry&MockObject $indexerRegistry;
    private CascadeLog&MockObject $log;
    private ProductCountFromIndex $plugin;
    private bool $proceedCalled = false;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isIndexedProductCountEnabled')->willReturn(true);

        $this->indexedCount = $this->createMock(IndexedProductCount::class);
        $this->indexedCount->method('isAvailable')->willReturn(true);

        $this->indexerRegistry = $this->createMock(IndexerRegistry::class);
        $this->indexerRegistry->method('get')->willReturn($this->indexer(false, true));

        $this->log = $this->createMock(CascadeLog::class);

        $this->plugin = new ProductCountFromIndex(
            $this->config,
            $this->indexedCount,
            $this->indexerRegistry,
            $this->log
        );
    }

    /**
     * The default for every category collection in the application: nothing asked for counts, so
     * nothing is added to the load.
     */
    public function testPassesThroughWhenCountingWasNeverRequested(): void
    {
        $collection = $this->collection();
        $collection->expects($this->never())->method('setLoadProductCount');

        $this->plugin->aroundLoad($collection, $this->proceed($collection));

        $this->assertTrue($this->proceedCalled);
    }

    public function testPassesThroughWhenTheFeatureIsSwitchedOff(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isIndexedProductCountEnabled')->willReturn(false);
        $plugin = new ProductCountFromIndex($config, $this->indexedCount, $this->indexerRegistry, $this->log);

        $collection = $this->collection();
        $collection->expects($this->never())->method('setLoadProductCount');

        $plugin->beforeSetLoadProductCount($collection, true);
        $plugin->aroundLoad($collection, $this->proceed($collection));

        $this->assertTrue($this->proceedCalled);
    }

    /**
     * All Store Views has no index table of its own, and picking a store view for it would answer
     * a question the admin did not ask.
     */
    public function testPassesThroughForAllStoreViews(): void
    {
        $collection = $this->collection(storeId: 0);
        $collection->expects($this->never())->method('setLoadProductCount');

        $this->plugin->beforeSetLoadProductCount($collection, true);
        $this->plugin->aroundLoad($collection, $this->proceed($collection));

        $this->assertTrue($this->proceedCalled);
    }

    public function testPassesThroughWhenTheStoreHasNoIndexTable(): void
    {
        $indexedCount = $this->createMock(IndexedProductCount::class);
        $indexedCount->method('isAvailable')->willReturn(false);
        $plugin = new ProductCountFromIndex($this->config, $indexedCount, $this->indexerRegistry, $this->log);

        $collection = $this->collection();
        $collection->expects($this->never())->method('setLoadProductCount');

        $plugin->beforeSetLoadProductCount($collection, true);
        $plugin->aroundLoad($collection, $this->proceed($collection));

        $this->assertTrue($this->proceedCalled);
    }

    /**
     * An invalidated update-on-save index has nothing feeding it until someone reindexes, so its
     * numbers are fiction. A scheduled one is current to within a cron run whatever its validity
     * flag says between partial reindexes.
     */
    public function testPassesThroughWhenAnUpdateOnSaveIndexIsInvalid(): void
    {
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('get')->willReturn($this->indexer(false, false));
        $plugin = new ProductCountFromIndex($this->config, $this->indexedCount, $registry, $this->log);

        $collection = $this->collection();
        $collection->expects($this->never())->method('setLoadProductCount');

        $plugin->beforeSetLoadProductCount($collection, true);
        $plugin->aroundLoad($collection, $this->proceed($collection));

        $this->assertTrue($this->proceedCalled);
    }

    public function testUsesAScheduledIndexEvenWhenItIsMarkedInvalid(): void
    {
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('get')->willReturn($this->indexer(true, false));
        $plugin = new ProductCountFromIndex($this->config, $this->indexedCount, $registry, $this->log);

        $items = [31 => new DataObject(['id' => 31])];
        $collection = $this->collection(items: $items);
        $this->indexedCount->method('fetch')->willReturn([31 => 9]);

        $plugin->beforeSetLoadProductCount($collection, true);
        $plugin->aroundLoad($collection, $this->proceed($collection));

        $this->assertSame(9, $items[31]->getProductCount());
    }

    public function testSuppressesCoreCountingAndCountsAbsentCategoriesAsZero(): void
    {
        $items = [
            31 => new DataObject(['id' => 31]),
            32 => new DataObject(['id' => 32]),
        ];
        $collection = $this->collection(items: $items);

        // Core's own load() adds these two for its counting; the plugin keeps the loaded items
        // identical whether or not it takes over.
        $collection->expects($this->exactly(2))->method('addAttributeToSelect');
        $collection->expects($this->once())->method('setLoadProductCount')->with(false);
        $collection->expects($this->never())->method('loadProductCount');

        $this->indexedCount->expects($this->once())
            ->method('fetch')
            ->with(1, [31, 32])
            ->willReturn([31 => 7]);

        $this->plugin->beforeSetLoadProductCount($collection, true);
        $this->plugin->aroundLoad($collection, $this->proceed($collection));

        $this->assertSame(7, $items[31]->getProductCount());
        $this->assertSame(0, $items[32]->getProductCount());
    }

    /**
     * Never worse than core: a broken index query runs exactly the counting that was suppressed.
     */
    public function testFallsBackToCoreCountingWhenTheIndexQueryFails(): void
    {
        $items = [31 => new DataObject(['id' => 31])];
        $collection = $this->collection(items: $items);

        $this->indexedCount->method('fetch')
            ->willThrowException(new \RuntimeException('index table vanished'));

        $collection->expects($this->once())
            ->method('loadProductCount')
            ->with($items, true, true);
        $this->log->expects($this->once())->method('productCountFallback');

        $this->plugin->beforeSetLoadProductCount($collection, true);
        $this->plugin->aroundLoad($collection, $this->proceed($collection));
    }

    /**
     * @param array<int, DataObject> $items
     */
    private function collection(int $storeId = 1, array $items = []): Collection&MockObject
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('isLoaded')->willReturn(false);
        $collection->method('getStoreId')->willReturn($storeId);
        $collection->method('getItems')->willReturn($items);

        return $collection;
    }

    private function proceed(Collection $collection): callable
    {
        return function () use ($collection): Collection {
            $this->proceedCalled = true;

            return $collection;
        };
    }

    private function indexer(bool $scheduled, bool $valid): IndexerInterface&MockObject
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn($scheduled);
        $indexer->method('isValid')->willReturn($valid);

        return $indexer;
    }
}
