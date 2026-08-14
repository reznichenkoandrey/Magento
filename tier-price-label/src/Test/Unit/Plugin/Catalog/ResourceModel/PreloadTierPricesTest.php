<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\Test\Unit\Plugin\Catalog\ResourceModel;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\TierPriceLabel\Plugin\Catalog\ResourceModel\PreloadTierPrices;

class PreloadTierPricesTest extends TestCase
{
    private const CORE_TIER_PRICE_FLAG = 'tier_price_added';

    private PreloadTierPrices $plugin;

    protected function setUp(): void
    {
        $this->plugin = new PreloadTierPrices();
    }

    public function testLoadsTierPricesForAFlaggedCollection(): void
    {
        $collection = $this->createCollection([PreloadTierPrices::PRELOAD_FLAG => true]);
        $collection->expects($this->once())->method('addTierPriceData');

        $this->assertSame($collection, $this->plugin->afterLoad($collection, $collection));
    }

    public function testLeavesUnflaggedCollectionsAlone(): void
    {
        // Every storefront product collection enters this plugin; only listing collections
        // carry the flag, and the rest must not pay for a join they will never read.
        $collection = $this->createCollection([]);
        $collection->expects($this->never())->method('addTierPriceData');

        $this->plugin->afterLoad($collection, $collection);
    }

    public function testDoesNotQueryTwiceWhenCoreAlreadyLoadedTierPrices(): void
    {
        $collection = $this->createCollection([
            PreloadTierPrices::PRELOAD_FLAG => true,
            self::CORE_TIER_PRICE_FLAG => true,
        ]);
        $collection->expects($this->never())->method('addTierPriceData');

        $this->plugin->afterLoad($collection, $collection);
    }

    /**
     * @param array<string, bool> $flags
     */
    private function createCollection(array $flags): Collection&MockObject
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('getFlag')->willReturnCallback(
            static fn (string $flag) => $flags[$flag] ?? null
        );

        return $collection;
    }
}
