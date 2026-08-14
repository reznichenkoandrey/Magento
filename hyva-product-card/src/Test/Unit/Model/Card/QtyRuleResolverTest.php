<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Test\Unit\Model\Card;

use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductCard\Model\Card\QtyRuleResolver;

class QtyRuleResolverTest extends TestCase
{
    private StockRegistryInterface $stockRegistry;
    private QtyRuleResolver $resolver;

    protected function setUp(): void
    {
        $this->stockRegistry = $this->createMock(StockRegistryInterface::class);
        $this->resolver = new QtyRuleResolver($this->stockRegistry);
    }

    public function testReadsTheStockItemGettersRatherThanTheUseConfigFlags(): void
    {
        // The stock item model resolves use_config_* internally, including the customer-group
        // dimension of min_sale_qty. A resolver that re-implemented the ladder would have to be
        // told about the flags; this one must not ask for them at all.
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsQtyDecimal')->willReturn(false);
        $stockItem->method('getMinSaleQty')->willReturn(12.0);
        $stockItem->method('getQtyIncrements')->willReturn(6);
        $stockItem->method('getMaxSaleQty')->willReturn(60.0);
        $stockItem->expects($this->never())->method('getUseConfigMinSaleQty');
        $stockItem->expects($this->never())->method('getUseConfigQtyIncrements');

        $rules = $this->resolver->fromStockItem($stockItem);

        $this->assertSame(12.0, $rules->getMin());
        $this->assertSame(6.0, $rules->getStep());
        $this->assertSame(60.0, $rules->getMax());
        $this->assertFalse($rules->isDecimal());
    }

    public function testFalseIncrementsBecomeAUsableStep(): void
    {
        // getQtyIncrements() returns false when increments are disabled — a stepper cannot step by
        // false, and 1 is the only honest reading of "no increment configured".
        $rules = $this->resolver->fromStockItem($this->stockItem(1.0, false, false, 0.0));

        $this->assertSame(1.0, $rules->getStep());
    }

    public function testDecimalProductsGetAFineGrainedStep(): void
    {
        $rules = $this->resolver->fromStockItem($this->stockItem(0.0, 0.0, true, 0.0));

        $this->assertSame(0.0001, $rules->getStep());
        $this->assertTrue($rules->isDecimal());
    }

    public function testBothBoundsAreSnappedOntoTheIncrementLadder(): void
    {
        // Core rejects any quantity that does not divide exactly by qty_increments
        // (StockStateProvider::checkQtyIncrements). "Minimum 10, increments of 6" is therefore not
        // 10, 16, 22 — the smallest legal quantity is 12, and the largest under a ceiling of 50
        // is 48. Emitting the raw bounds would put an illegal number in the input by default.
        $rules = $this->resolver->fromStockItem($this->stockItem(10.0, 6, false, 50.0));

        $this->assertSame(12.0, $rules->getMin());
        $this->assertSame(48.0, $rules->getMax());
    }

    public function testDecimalBoundsSurviveTheirOwnDivision(): void
    {
        // 12 / 0.1 is 119.99999999999999 in binary floating point; without rounding before the
        // ceil() the minimum would be snapped up a whole step to 12.1.
        $rules = $this->resolver->fromStockItem($this->stockItem(12.0, 0.1, true, 0.0));

        $this->assertSame(12.0, $rules->getMin());
    }

    public function testAnUnbuyableProductIsReportedRatherThanRoundedIntoBuyability(): void
    {
        // Minimum 12, increments of 10, ceiling 15: the aligned ceiling (10) is below the aligned
        // minimum (20). Inventing a number here would move the rejection to the checkout.
        $rules = $this->resolver->fromStockItem($this->stockItem(12.0, 10, false, 15.0));

        $this->assertSame(20.0, $rules->getMin());
        $this->assertSame(10.0, $rules->getMax());
    }

    public function testZeroMaximumMeansNoCeilingRatherThanACeilingOfZero(): void
    {
        // Zero is how cataloginventory_stock_item spells "unlimited"; passing it through as a
        // number would make the stepper refuse its own minimum.
        $rules = $this->resolver->fromStockItem($this->stockItem(2.0, 1.0, false, 0.0));

        $this->assertNull($rules->getMax());
    }

    public function testMinimumFallsBackToTheStepWhenTheStockRowHasNone(): void
    {
        $rules = $this->resolver->fromStockItem($this->stockItem(0.0, 5.0, false, 0.0));

        $this->assertSame(5.0, $rules->getMin());
    }

    public function testResolveMemoisesSoTheSecondCallerCostsNothing(): void
    {
        $this->stockRegistry->expects($this->once())
            ->method('getStockItem')
            ->with(42)
            ->willReturn($this->stockItem(1.0, 1.0, false, 0.0));

        $first = $this->resolver->resolve(42);
        $second = $this->resolver->resolve(42);

        $this->assertSame($first, $second);
    }

    public function testAlternativeRegistriesThatThrowDegradeToDefaults(): void
    {
        $this->stockRegistry->method('getStockItem')
            ->willThrowException(new NoSuchEntityException(__('no stock row')));

        $rules = $this->resolver->resolve(7);

        $this->assertSame(1.0, $rules->getMin());
        $this->assertSame(1.0, $rules->getStep());
        $this->assertNull($rules->getMax());
    }

    private function stockItem(float $min, float|bool $increments, bool $isDecimal, float $max): StockItemInterface
    {
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsQtyDecimal')->willReturn($isDecimal);
        $stockItem->method('getMinSaleQty')->willReturn($min);
        $stockItem->method('getQtyIncrements')->willReturn($increments);
        $stockItem->method('getMaxSaleQty')->willReturn($max);

        return $stockItem;
    }
}
