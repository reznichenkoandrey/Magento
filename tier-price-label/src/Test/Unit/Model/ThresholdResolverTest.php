<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\Test\Unit\Model;

use Magento\Catalog\Pricing\Price\TierPrice;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\Pricing\SaleableInterface;
use PHPUnit\Framework\TestCase;
use Scr1be\TierPriceLabel\Model\ThresholdResolver;

class ThresholdResolverTest extends TestCase
{
    private ThresholdResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ThresholdResolver();
    }

    public function testPicksTheQuantityOfTheCheapestRungNotTheHighestOne(): void
    {
        // A non-monotonic ladder: buying more is not automatically cheaper.
        $product = $this->createProduct([
            $this->rung(5.0, 9.00),
            $this->rung(10.0, 9.50),
        ]);

        $this->assertSame(5.0, $this->resolver->resolve($product));
    }

    public function testTieResolvesToTheLowestQuantity(): void
    {
        $product = $this->createProduct([
            $this->rung(10.0, 9.00),
            $this->rung(5.0, 9.00),
        ]);

        $this->assertSame(5.0, $this->resolver->resolve($product));
    }

    public function testDefersToCoreWhenTheCheapestRungIsReachableAtASingleUnit(): void
    {
        // "From 1 pcs" is not a quantity story — the shopper already has that price.
        $product = $this->createProduct([
            $this->rung(1.0, 8.00),
            $this->rung(10.0, 9.00),
        ]);

        $this->assertNull($this->resolver->resolve($product));
    }

    public function testDefersToCoreWithoutTierPrices(): void
    {
        $this->assertNull($this->resolver->resolve($this->createProduct([])));
    }

    public function testSkipsRungsWithoutAQuantityOrAnAmount(): void
    {
        $product = $this->createProduct([
            ['price_qty' => 5.0],
            ['price' => $this->createMock(AmountInterface::class)],
            $this->rung(20.0, 7.00),
        ]);

        $this->assertSame(20.0, $this->resolver->resolve($product));
    }

    public function testDefersToCoreWhenThePriceInfoHasNoTierPrice(): void
    {
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willThrowException(new \InvalidArgumentException());

        $product = $this->createMock(SaleableInterface::class);
        $product->method('getPriceInfo')->willReturn($priceInfo);

        $this->assertNull($this->resolver->resolve($product));
    }

    /**
     * @param array<int, array<string, mixed>> $tierPriceList
     */
    private function createProduct(array $tierPriceList): SaleableInterface
    {
        $tierPrice = $this->createMock(TierPrice::class);
        $tierPrice->method('getTierPriceList')->willReturn($tierPriceList);

        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->with(TierPrice::PRICE_CODE)->willReturn($tierPrice);

        $product = $this->createMock(SaleableInterface::class);
        $product->method('getPriceInfo')->willReturn($priceInfo);

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function rung(float $qty, float $value): array
    {
        $amount = $this->createMock(AmountInterface::class);
        $amount->method('getValue')->willReturn($value);

        return ['price_qty' => $qty, 'price' => $amount];
    }
}
