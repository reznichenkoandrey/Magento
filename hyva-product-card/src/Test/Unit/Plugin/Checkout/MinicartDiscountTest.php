<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Test\Unit\Plugin\Checkout;

use Magento\Catalog\Model\Product;
use Magento\Checkout\CustomerData\ItemPoolInterface;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductCard\Model\Config;
use Scr1be\HyvaProductCard\Plugin\Checkout\MinicartDiscount;

class MinicartDiscountTest extends TestCase
{
    private PriceCurrencyInterface&MockObject $priceCurrency;
    private Config&MockObject $config;
    private ItemPoolInterface&MockObject $subject;
    private MinicartDiscount $plugin;

    protected function setUp(): void
    {
        $this->priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $this->priceCurrency->method('format')->willReturn('$100.00');
        $this->config = $this->createMock(Config::class);
        $this->subject = $this->createMock(ItemPoolInterface::class);
        $this->plugin = new MinicartDiscount($this->priceCurrency, $this->config);
    }

    public function testAStruckThroughPriceAppearsOnlyWhenThereIsADiscount(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $result = $this->plugin->afterGetItemData($this->subject, [], $this->item(100.0, 70.0));

        $this->assertTrue($result['has_discount']);
        $this->assertSame(100.0, $result['regular_price_value']);
        $this->assertSame('$100.00', $result['regular_price']);
    }

    public function testARoundingArtefactIsNotADiscount(): void
    {
        // Percentage catalogue rules land a fraction of a cent under the regular price all the
        // time; without the tolerance the drawer strikes through a price identical to the one
        // beside it.
        $this->config->method('isEnabled')->willReturn(true);

        $result = $this->plugin->afterGetItemData($this->subject, [], $this->item(100.0, 99.999999));

        $this->assertFalse($result['has_discount']);
        $this->assertNull($result['regular_price']);
    }

    public function testCoreKeysAreNeverTouched(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $result = $this->plugin->afterGetItemData(
            $this->subject,
            ['product_price' => '$70.00', 'qty' => 2],
            $this->item(100.0, 70.0)
        );

        $this->assertSame('$70.00', $result['product_price']);
        $this->assertSame(2, $result['qty']);
    }

    public function testAPriceTypeWithoutARegularPriceKeepsCoresSingleNumber(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willThrowException(new \InvalidArgumentException('no such price'));
        $product = $this->createMock(Product::class);
        $product->method('getPriceInfo')->willReturn($priceInfo);
        $item = $this->createMock(Item::class);
        $item->method('getProduct')->willReturn($product);

        $result = $this->plugin->afterGetItemData($this->subject, ['qty' => 1], $item);

        $this->assertSame(['qty' => 1], $result);
    }

    public function testTheKillSwitchLeavesTheArrayAlone(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $result = $this->plugin->afterGetItemData($this->subject, ['qty' => 1], $this->item(100.0, 70.0));

        $this->assertSame(['qty' => 1], $result);
    }

    private function item(float $regular, float $calculation): Item&MockObject
    {
        $amount = $this->createMock(AmountInterface::class);
        $amount->method('getValue')->willReturn($regular);
        $price = $this->createMock(PriceInterface::class);
        $price->method('getAmount')->willReturn($amount);
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturn($price);

        $product = $this->createMock(Product::class);
        $product->method('getPriceInfo')->willReturn($priceInfo);

        $item = $this->createMock(Item::class);
        $item->method('getProduct')->willReturn($product);
        $item->method('getCalculationPrice')->willReturn($calculation);

        return $item;
    }
}
