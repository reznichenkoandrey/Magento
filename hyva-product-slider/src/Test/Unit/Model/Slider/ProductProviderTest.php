<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Test\Unit\Model\Slider;

use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Api\ProductSourceInterface;
use Scr1be\HyvaProductSlider\Model\ProductSource\Pool;
use Scr1be\HyvaProductSlider\Model\Slider\ProductProvider;

class ProductProviderTest extends TestCase
{
    private ProductSourceInterface&MockObject $source;
    private CollectionFactory&MockObject $collectionFactory;
    private ProductCollection&MockObject $collection;
    private StockHelper&MockObject $stockHelper;

    protected function setUp(): void
    {
        $this->source = $this->createMock(ProductSourceInterface::class);
        $this->source->method('isAvailable')->willReturn(true);

        $this->collection = $this->createMock(ProductCollection::class);
        foreach (
            [
                'setStoreId', 'addIdFilter', 'addStoreFilter', 'setVisibility', 'addAttributeToFilter',
                'addMinimalPrice', 'addFinalPrice', 'addTaxPercents', 'addAttributeToSelect', 'addUrlRewrite',
            ] as $method
        ) {
            $this->collection->method($method)->willReturnSelf();
        }

        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $this->stockHelper = $this->createMock(StockHelper::class);
    }

    public function testAnUnknownSourceRendersNothingRatherThanThrowing(): void
    {
        $provider = $this->provider(new Pool([]));

        $this->assertSame([], $provider->getProducts($this->slider('telepathy', 12), 1));
    }

    public function testASourceThatFindsNothingSkipsTheCollectionEntirely(): void
    {
        $this->source->method('getProductIds')->willReturn([]);
        $this->collectionFactory->expects($this->never())->method('create');

        $this->assertSame([], $this->provider()->getProducts($this->slider('new', 12), 1));
    }

    public function testTheSourceIsAskedForMoreIdsThanTheSliderShows(): void
    {
        // Visibility, status and stock are applied after ranking, so a slider asking for exactly its
        // limit would render half empty the moment one bestseller went out of stock.
        $this->source->expects($this->once())
            ->method('getProductIds')
            ->with($this->anything(), 1, 36)
            ->willReturn([]);

        $this->provider()->getProducts($this->slider('new', 12), 1);
    }

    public function testTheCandidateCountIsCappedRegardlessOfTheLimit(): void
    {
        $this->source->expects($this->once())
            ->method('getProductIds')
            ->with($this->anything(), 1, 180)
            ->willReturn([]);

        $this->provider()->getProducts($this->slider('new', 100), 1);
    }

    public function testTheSourceOrderSurvivesTheCollection(): void
    {
        // The collection returns whatever order the storage engine finds; the ranking is the source's
        // and has to be restored, or "bestsellers" is just "some products".
        $this->source->method('getProductIds')->willReturn([30, 10, 20]);
        $this->stubCollectionItems([10, 20, 30]);

        $ids = $this->loadedIds($this->provider()->getProducts($this->slider('new', 12), 1));

        $this->assertSame([30, 10, 20], $ids);
    }

    public function testCandidatesTheCollectionDroppedAreSkipped(): void
    {
        // 20 is disabled, out of stock, or not visible individually. The slider closes the gap
        // instead of rendering a hole.
        $this->source->method('getProductIds')->willReturn([30, 20, 10]);
        $this->stubCollectionItems([10, 30]);

        $this->assertSame([30, 10], $this->loadedIds($this->provider()->getProducts($this->slider('new', 12), 1)));
    }

    public function testTheLimitIsAppliedAfterFiltering(): void
    {
        $this->source->method('getProductIds')->willReturn([1, 2, 3, 4, 5]);
        $this->stubCollectionItems([1, 2, 3, 4, 5]);

        $this->assertSame([1, 2], $this->loadedIds($this->provider()->getProducts($this->slider('new', 2), 1)));
    }

    public function testStockFilteringGoesThroughTheMsiAwareHelperMethod(): void
    {
        // `addIsInStockFilterToCollection()` respects `cataloginventory/options/show_out_of_stock` and
        // is the one `Magento_InventoryCatalog` decorates. The other helper method is neither.
        $this->source->method('getProductIds')->willReturn([1]);
        $this->stubCollectionItems([1]);

        $this->stockHelper->expects($this->once())
            ->method('addIsInStockFilterToCollection')
            ->with($this->collection);

        $this->provider()->getProducts($this->slider('new', 12), 1);
    }

    private function provider(?Pool $pool = null): ProductProvider
    {
        $visibility = $this->createMock(Visibility::class);
        $visibility->method('getVisibleInCatalogIds')->willReturn([2, 4]);

        $status = $this->createMock(Status::class);
        $status->method('getVisibleStatusIds')->willReturn([1]);

        $catalogConfig = $this->createMock(CatalogConfig::class);
        $catalogConfig->method('getProductAttributes')->willReturn(['name', 'price']);

        return new ProductProvider(
            $pool ?? new Pool(['new' => $this->source]),
            $this->collectionFactory,
            $catalogConfig,
            $visibility,
            $status,
            $this->stockHelper
        );
    }

    /**
     * @param int[] $ids
     */
    private function stubCollectionItems(array $ids): void
    {
        $products = [];
        foreach ($ids as $id) {
            $product = $this->createMock(Product::class);
            $product->method('getId')->willReturn($id);
            $products[] = $product;
        }

        $this->collection->method('getIterator')->willReturn(new \ArrayIterator($products));
    }

    /**
     * @param Product[] $products
     * @return int[]
     */
    private function loadedIds(array $products): array
    {
        return array_map(static fn (Product $product): int => (int) $product->getId(), $products);
    }

    private function slider(string $sourceType, int $limit): SliderInterface&MockObject
    {
        $slider = $this->createMock(SliderInterface::class);
        $slider->method('getSourceType')->willReturn($sourceType);
        $slider->method('getProductLimit')->willReturn($limit);

        return $slider;
    }
}
