<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\Test\Unit\Model;

use Magento\Catalog\Api\Data\ProductTierPriceInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\Customer\Model\Group;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Scr1be\TierPriceLabel\Model\CustomerGroupResolver;
use Scr1be\TierPriceLabel\Model\LadderProvider;

class LadderProviderTest extends TestCase
{
    private const CURRENT_GROUP_ID = 1;
    private const OTHER_GROUP_ID = 2;
    private const REGULAR_PRICE = 32.0;

    private LadderProvider $provider;

    protected function setUp(): void
    {
        $customerGroupResolver = $this->createMock(CustomerGroupResolver::class);
        $customerGroupResolver->method('getCurrentGroupId')->willReturn(self::CURRENT_GROUP_ID);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(
            static fn ($amount): string => '$' . number_format((float) $amount, 2)
        );

        $this->provider = new LadderProvider($customerGroupResolver, $storeManager, $priceCurrency);
    }

    public function testKeepsEligibleRungsSortedByQuantity(): void
    {
        $product = $this->createProduct([
            $this->tierPrice(self::CURRENT_GROUP_ID, 10.0, 9.00),
            $this->tierPrice(Group::CUST_GROUP_ALL, 5.0, 9.50),
        ]);

        $ladder = $this->provider->getLadder($product);

        $this->assertCount(2, $ladder);
        $this->assertSame(5.0, $ladder[0]->getQty());
        $this->assertSame(9.50, $ladder[0]->getValue());
        $this->assertSame('$9.50', $ladder[0]->getFormattedValue());
        $this->assertSame(10.0, $ladder[1]->getQty());
    }

    public function testDropsRungsBelongingToAnotherCustomerGroup(): void
    {
        $product = $this->createProduct([
            $this->tierPrice(self::OTHER_GROUP_ID, 5.0, 4.00),
            $this->tierPrice(self::CURRENT_GROUP_ID, 10.0, 9.00),
        ]);

        $ladder = $this->provider->getLadder($product);

        $this->assertCount(1, $ladder);
        $this->assertSame(10.0, $ladder[0]->getQty());
    }

    public function testCollapsesDuplicateQuantitiesToTheCheapestRow(): void
    {
        // One row per group/website is legal on the same quantity; the shopper gets the
        // cheaper one, so that is the one the calculator must quote.
        $product = $this->createProduct([
            $this->tierPrice(Group::CUST_GROUP_ALL, 5.0, 9.50),
            $this->tierPrice(self::CURRENT_GROUP_ID, 5.0, 8.50),
        ]);

        $ladder = $this->provider->getLadder($product);

        $this->assertCount(1, $ladder);
        $this->assertSame(8.50, $ladder[0]->getValue());
    }

    public function testKeepsRungsCoreWouldHideBehindTheFinalPrice(): void
    {
        // Deliberate: a rung more expensive than today's price still belongs in the payload,
        // otherwise the client-side calculator cannot explain a qty the shopper types.
        $product = $this->createProduct([
            $this->tierPrice(self::CURRENT_GROUP_ID, 5.0, self::REGULAR_PRICE + 1.0),
        ]);

        $this->assertCount(1, $this->provider->getLadder($product));
    }

    /**
     * @param ProductTierPriceInterface[] $tierPrices
     */
    private function createProduct(array $tierPrices): Product
    {
        $regularPrice = $this->createMock(PriceInterface::class);
        $regularPrice->method('getValue')->willReturn(self::REGULAR_PRICE);

        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->with(RegularPrice::PRICE_CODE)->willReturn($regularPrice);

        $product = $this->createMock(Product::class);
        $product->method('getPriceInfo')->willReturn($priceInfo);
        $product->method('getTierPrices')->willReturn($tierPrices);

        return $product;
    }

    private function tierPrice(int $groupId, float $qty, float $value): ProductTierPriceInterface
    {
        $tierPrice = $this->createMock(ProductTierPriceInterface::class);
        $tierPrice->method('getCustomerGroupId')->willReturn($groupId);
        $tierPrice->method('getQty')->willReturn($qty);
        $tierPrice->method('getValue')->willReturn($value);
        // No extension attributes: the row applies to every website, which is the default a
        // tier price gets when it is saved from the product form.
        $tierPrice->method('getExtensionAttributes')->willReturn(null);

        return $tierPrice;
    }
}
