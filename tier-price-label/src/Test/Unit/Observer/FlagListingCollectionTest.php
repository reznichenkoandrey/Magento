<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\Test\Unit\Observer;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Scr1be\TierPriceLabel\Observer\FlagListingCollection;
use Scr1be\TierPriceLabel\Plugin\Catalog\ResourceModel\PreloadTierPrices;

class FlagListingCollectionTest extends TestCase
{
    private FlagListingCollection $observer;

    protected function setUp(): void
    {
        $this->observer = new FlagListingCollection();
    }

    public function testFlagsAProductCollection(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())
            ->method('setFlag')
            ->with(PreloadTierPrices::PRELOAD_FLAG, true);

        $this->observer->execute($this->createEvent($collection));
    }

    public function testIgnoresCollectionsWithoutTheBulkTierPriceLoader(): void
    {
        // Third-party listing blocks reuse this event with their own collection types.
        $collection = $this->createMock(DataCollection::class);
        $collection->expects($this->never())->method('setFlag');

        $this->observer->execute($this->createEvent($collection));
    }

    public function testIgnoresAnEventWithoutACollection(): void
    {
        $this->expectNotToPerformAssertions();

        $this->observer->execute($this->createEvent(null));
    }

    private function createEvent(?object $collection): Observer
    {
        $observer = $this->createMock(Observer::class);
        $observer->method('getData')->with('collection')->willReturn($collection);

        return $observer;
    }
}
