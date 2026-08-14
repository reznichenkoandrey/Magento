<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Test\Unit\Observer;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\CatalogInventory\Model\StockRegistryPreloader;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductCard\Model\Config;
use Scr1be\HyvaProductCard\Observer\PreloadQtyRules;

class PreloadQtyRulesTest extends TestCase
{
    private StockRegistryPreloader&MockObject $preloader;
    private Config&MockObject $config;
    private PreloadQtyRules $observer;

    protected function setUp(): void
    {
        $this->preloader = $this->createMock(StockRegistryPreloader::class);
        $this->config = $this->createMock(Config::class);
        $this->observer = new PreloadQtyRules($this->preloader, $this->config);
    }

    public function testOnePreloadCallCoversTheWholePage(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->preloader->expects($this->once())
            ->method('preloadStockItems')
            ->with([1, 2, 3]);

        $this->observer->execute($this->event($this->collection([1, 2, 3])));
    }

    public function testDuplicateIdsAreCollapsedBeforeTheQuery(): void
    {
        // A collection can carry the same product twice — related-product blocks do it routinely.
        $this->config->method('isEnabled')->willReturn(true);
        $this->preloader->expects($this->once())
            ->method('preloadStockItems')
            ->with([4, 5]);

        $this->observer->execute($this->event($this->collection([4, 5, 4])));
    }

    public function testAnEmptyCollectionIsNotWorthAQuery(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->preloader->expects($this->never())->method('preloadStockItems');

        $this->observer->execute($this->event($this->collection([])));
    }

    public function testTheKillSwitchIsCheckedBeforeAnythingElse(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->preloader->expects($this->never())->method('preloadStockItems');

        $this->observer->execute($this->event($this->collection([1])));
    }

    public function testAnEventCarryingSomethingOtherThanACollectionIsIgnored(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->preloader->expects($this->never())->method('preloadStockItems');

        $observer = new Observer();
        $observer->setData('collection', 'not a collection');

        $this->observer->execute($observer);
    }

    private function event(Collection&MockObject $collection): Observer
    {
        $observer = new Observer();
        $observer->setData('collection', $collection);

        return $observer;
    }

    /**
     * @param int[] $ids
     */
    private function collection(array $ids): Collection&MockObject
    {
        $items = array_map(static fn (int $id): DataObject => new DataObject(['id' => $id]), $ids);

        $collection = $this->createMock(Collection::class);
        $collection->method('getItems')->willReturn($items);

        return $collection;
    }
}
