<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\Test\Unit\Plugin\Pricing\Render;

use Magento\Catalog\Pricing\Price\MinimalPriceCalculatorInterface;
use Magento\Catalog\Pricing\Render\FinalPriceBox;
use Magento\Framework\Phrase;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\SaleableInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\TierPriceLabel\Model\QtyFormatter;
use Scr1be\TierPriceLabel\Model\ThresholdResolver;
use Scr1be\TierPriceLabel\Plugin\Pricing\Render\MinimalAmountLabel;

class MinimalAmountLabelTest extends TestCase
{
    private const CORE_OUTPUT = '<span class="price-label">As low as</span>';

    private const PLUGIN_OUTPUT = '<span class="price-label">From 5 pcs</span>';

    private MinimalPriceCalculatorInterface&MockObject $minimalPriceCalculator;

    private ThresholdResolver&MockObject $thresholdResolver;

    private MinimalAmountLabel $plugin;

    protected function setUp(): void
    {
        $this->minimalPriceCalculator = $this->createMock(MinimalPriceCalculatorInterface::class);
        $this->thresholdResolver = $this->createMock(ThresholdResolver::class);

        $qtyFormatter = $this->createMock(QtyFormatter::class);
        $qtyFormatter->method('format')->willReturnCallback(
            static fn (float $qty): string => (string) (int) $qty
        );

        $this->plugin = new MinimalAmountLabel(
            $this->minimalPriceCalculator,
            $this->thresholdResolver,
            $qtyFormatter
        );
    }

    public function testFallsThroughToCoreWhenThereIsNoMinimalAmount(): void
    {
        $subject = $this->createSubject();
        $this->minimalPriceCalculator->method('getAmount')->willReturn(null);
        $subject->expects($this->never())->method('renderAmount');

        $this->assertSame(self::CORE_OUTPUT, $this->plugin->aroundRenderAmountMinimal($subject, $this->proceed()));
    }

    public function testFallsThroughToCoreWhenNoQuantityIsWorthNaming(): void
    {
        $subject = $this->createSubject();
        $this->minimalPriceCalculator->method('getAmount')->willReturn($this->createMock(AmountInterface::class));
        $this->thresholdResolver->method('resolve')->willReturn(null);
        $subject->expects($this->never())->method('renderAmount');

        $this->assertSame(self::CORE_OUTPUT, $this->plugin->aroundRenderAmountMinimal($subject, $this->proceed()));
    }

    public function testRebuildsTheCoreRenderCallWithOnlyTheLabelReplaced(): void
    {
        $amount = $this->createMock(AmountInterface::class);
        $subject = $this->createSubject('product-price-1');

        $this->minimalPriceCalculator->method('getAmount')->willReturn($amount);
        $this->thresholdResolver->method('resolve')->willReturn(5.0);

        $subject->expects($this->once())
            ->method('renderAmount')
            ->with(
                $this->identicalTo($amount),
                $this->callback(function (array $arguments): bool {
                    /** @var Phrase $label */
                    $label = $arguments['display_label'];

                    $this->assertSame('From %1 pcs —', $label->getText());
                    $this->assertSame(['5'], $label->getArguments());
                    // The block's own resolved id wins, exactly as in core.
                    $this->assertSame('product-price-1', $arguments['price_id']);
                    // Core passes these four keys and nothing else.
                    $this->assertSame(
                        ['display_label', 'price_id', 'include_container', 'skip_adjustments'],
                        array_keys($arguments)
                    );
                    $this->assertFalse($arguments['include_container']);
                    $this->assertFalse($arguments['skip_adjustments']);

                    return true;
                })
            )
            ->willReturn(self::PLUGIN_OUTPUT);

        $this->assertSame(self::PLUGIN_OUTPUT, $this->plugin->aroundRenderAmountMinimal($subject, $this->proceed()));
    }

    /**
     * Core never renders an empty id here: PriceBox::getPriceId() returning nothing falls back
     * to "product-minimal-price-<id>". Hyvä's amount template gates its Alpine id scoping on a
     * truthy id, so an empty one would unbind the price node from swatch updates.
     */
    public function testFallsBackToCoresMinimalPriceIdWhenTheBlockResolvesNone(): void
    {
        $subject = $this->createSubject('');

        $this->minimalPriceCalculator->method('getAmount')->willReturn($this->createMock(AmountInterface::class));
        $this->thresholdResolver->method('resolve')->willReturn(5.0);

        $subject->expects($this->once())
            ->method('renderAmount')
            ->with(
                $this->anything(),
                $this->callback(
                    static fn (array $arguments): bool => $arguments['price_id'] === 'product-minimal-price-42'
                )
            )
            ->willReturn(self::PLUGIN_OUTPUT);

        $this->plugin->aroundRenderAmountMinimal($subject, $this->proceed());
    }

    private function createSubject(string $priceId = ''): FinalPriceBox&MockObject
    {
        $product = $this->createMock(SaleableInterface::class);
        $product->method('getId')->willReturn(42);

        $subject = $this->createMock(FinalPriceBox::class);
        $subject->method('getSaleableItem')->willReturn($product);
        $subject->method('getPriceId')->willReturn($priceId);

        return $subject;
    }

    private function proceed(): callable
    {
        return static fn (): string => self::CORE_OUTPUT;
    }
}
