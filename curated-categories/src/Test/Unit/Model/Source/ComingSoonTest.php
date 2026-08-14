<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model\Source;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Model\Config;
use Scr1be\CuratedCategories\Model\Source\ComingSoon;

class ComingSoonTest extends TestCase
{
    private const LIMIT = 12;

    private Config&MockObject $config;
    private CollectionFactory&MockObject $collectionFactory;
    private ComingSoon $source;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getLimit')->willReturn(self::LIMIT);

        $this->collectionFactory = $this->createMock(CollectionFactory::class);

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturnCallback(
            static fn (): \DateTime => new \DateTime('2026-08-14 17:45:00', new \DateTimeZone('Europe/Kyiv'))
        );

        $this->source = new ComingSoon($this->config, $timezone, $this->collectionFactory);
    }

    /**
     * The seam that matters: an EAV datetime is not a timestamp, and the only comparison Magento
     * knows how to make against one is `['date' => true, ...]` with a locale-formatted boundary —
     * the same shape `Magento\Catalog\Block\Product\NewProduct::_getProductCollection()` builds for
     * `news_from_date`. Reimplementing it against `catalog_product_entity_datetime` is how a module
     * ends up a day out for half the year.
     *
     * `from`, not a strict greater-than, and midnight rather than the current time: a product
     * restocking this afternoon is still coming soon this morning.
     */
    public function testFiltersFromTheStartOfTodayUsingCoresDateComparison(): void
    {
        $collection = $this->collection([]);

        $collection->expects($this->once())
            ->method('addAttributeToFilter')
            ->with(
                ComingSoon::ATTRIBUTE_CODE,
                ['date' => true, 'from' => '2026-08-14 00:00:00']
            )
            ->willReturnSelf();

        $this->collectionFactory->method('create')->willReturn($collection);

        $this->source->getProductIds();
    }

    public function testRanksBySoonestRestockAndCapsAtTheConfiguredLimit(): void
    {
        $collection = $this->collection([31, 32]);

        $collection->expects($this->once())
            ->method('addAttributeToSort')
            ->with(ComingSoon::ATTRIBUTE_CODE, 'ASC')
            ->willReturnSelf();
        $collection->expects($this->once())->method('setPageSize')->with(self::LIMIT)->willReturnSelf();
        $collection->expects($this->once())->method('setStoreId')->with(Store::DEFAULT_STORE_ID)->willReturnSelf();

        $this->collectionFactory->method('create')->willReturn($collection);

        $this->assertSame([31, 32], $this->source->getProductIds());
    }

    public function testHasNoTargetUntilACategoryIsPicked(): void
    {
        $this->config->method('getCategoryId')->willReturn(0);

        $this->assertNull($this->source->getTarget());
    }

    /**
     * @param int[] $productIds
     */
    private function collection(array $productIds): Collection&MockObject
    {
        $items = [];
        foreach ($productIds as $productId) {
            $product = $this->createMock(Product::class);
            $product->method('getId')->willReturn((string) $productId);
            $items[] = $product;
        }

        $collection = $this->createMock(Collection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('addAttributeToSort')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('setCurPage')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        return $collection;
    }
}
