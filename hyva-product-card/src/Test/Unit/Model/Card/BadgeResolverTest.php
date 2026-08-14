<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Test\Unit\Model\Card;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductCard\Model\Card\Badge;
use Scr1be\HyvaProductCard\Model\Card\BadgeResolver;
use Scr1be\HyvaProductCard\Model\Card\StockPresentation;
use Scr1be\HyvaProductCard\Model\Config;

class BadgeResolverTest extends TestCase
{
    private Config&MockObject $config;
    private TimezoneInterface&MockObject $timezone;
    private BadgeResolver $resolver;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->timezone = $this->createMock(TimezoneInterface::class);

        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isNewBadgeEnabled')->willReturn(true);
        $this->config->method('isSaleBadgeEnabled')->willReturn(true);
        $this->config->method('getSaleMinPercent')->willReturn(5.0);

        $this->resolver = new BadgeResolver($this->config, $this->timezone);
    }

    public function testProducesNothingWhenTheModuleIsOff(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(false);
        $resolver = new BadgeResolver($config, $this->timezone);

        $this->assertSame([], $resolver->resolve($this->product(100.0, 50.0, '2026-01-01')));
    }

    public function testAProductWithNoStartDateIsNotNew(): void
    {
        // isScopeDateInInterval() answers true when both bounds are empty, which is every product
        // in the catalogue. Without the guard the whole store would be badged New.
        $this->timezone->expects($this->never())->method('isScopeDateInInterval');

        $badges = $this->resolver->resolve($this->product(100.0, 100.0, ''));

        $this->assertSame([], $badges);
    }

    public function testNewBadgeFollowsTheStoreScopedInterval(): void
    {
        $this->timezone->expects($this->once())
            ->method('isScopeDateInInterval')
            ->with(0, '2026-01-01', '2026-12-31')
            ->willReturn(true);

        $badges = $this->resolver->resolve($this->product(100.0, 100.0, '2026-01-01', '2026-12-31'));

        $this->assertCount(1, $badges);
        $this->assertSame(Badge::CODE_NEW, $badges[0]->getCode());
    }

    public function testAnOpenEndedNewWindowPassesNullRatherThanAnEmptyString(): void
    {
        $this->timezone->expects($this->once())
            ->method('isScopeDateInInterval')
            ->with(0, '2026-01-01', null)
            ->willReturn(true);

        $this->resolver->resolve($this->product(100.0, 100.0, '2026-01-01'));
    }

    public function testSaleBadgeCarriesTheDiscountItMeasured(): void
    {
        $badges = $this->resolver->resolve($this->product(100.0, 70.0, ''));

        $this->assertCount(1, $badges);
        $this->assertSame(Badge::CODE_SALE, $badges[0]->getCode());
        $this->assertEqualsWithDelta(30.0, $badges[0]->getValue(), 0.0001);
    }

    public function testADiscountBelowTheConfiguredFloorIsNotASale(): void
    {
        // 100.00 -> 99.00 is 1%: a rounding artefact of a catalogue rule, not a marketing message.
        $this->assertSame([], $this->resolver->resolve($this->product(100.0, 99.0, '')));
    }

    public function testAPriceEqualToTheRegularOneIsNotADiscount(): void
    {
        $this->assertNull($this->resolver->getDiscountPercent($this->product(42.0, 42.0, '')));
    }

    public function testAPriceTypeWithoutARegularPriceSimplyHasNoSaleStory(): void
    {
        $product = $this->createMock(Product::class);
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willThrowException(new \InvalidArgumentException('no such price'));
        $product->method('getPriceInfo')->willReturn($priceInfo);
        $product->method('getStoreId')->willReturn(0);
        $product->method('getData')->willReturn('');

        $this->assertNull($this->resolver->getDiscountPercent($product));
        $this->assertSame([], $this->resolver->resolve($product));
    }

    public function testUrgencyOutranksPriceAndPriceOutranksNovelty(): void
    {
        $this->timezone->method('isScopeDateInInterval')->willReturn(true);
        $stock = new StockPresentation(true, 'Only 2 left', true, 2.0);

        $badges = $this->resolver->resolve($this->product(100.0, 70.0, '2026-01-01'), $stock);

        $this->assertSame(
            [Badge::CODE_LOW_STOCK, Badge::CODE_SALE, Badge::CODE_NEW],
            array_map(static fn (Badge $badge): string => $badge->getCode(), $badges)
        );
    }

    public function testStockThatIsNotLowContributesNoBadge(): void
    {
        $stock = new StockPresentation(true, 'In stock', false, 40.0);

        $this->assertSame([], $this->resolver->resolve($this->product(10.0, 10.0, ''), $stock));
    }

    private function product(float $regular, float $final, string $newsFrom, string $newsTo = ''): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getStoreId')->willReturn(0);
        // A callback rather than willReturnMap: the resolver calls getData() with one argument and
        // a map row would have to describe both parameters of the real signature to ever match.
        $product->method('getData')->willReturnCallback(
            static fn (string $key): string => $key === 'news_from_date' ? $newsFrom : $newsTo
        );
        $product->method('getPriceInfo')->willReturn($this->priceInfo($regular, $final));

        return $product;
    }

    private function priceInfo(float $regular, float $final): PriceInfoInterface&MockObject
    {
        $regularPrice = $this->price($regular);
        $finalPrice = $this->price($final);

        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturnCallback(
            static fn (string $code): PriceInterface => $code === RegularPrice::PRICE_CODE
                ? $regularPrice
                : $finalPrice
        );

        return $priceInfo;
    }

    private function price(float $value): PriceInterface&MockObject
    {
        $amount = $this->createMock(AmountInterface::class);
        $amount->method('getValue')->willReturn($value);

        $price = $this->createMock(PriceInterface::class);
        $price->method('getAmount')->willReturn($amount);

        return $price;
    }
}
