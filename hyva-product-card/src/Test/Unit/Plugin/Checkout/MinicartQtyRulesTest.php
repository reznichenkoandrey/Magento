<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Test\Unit\Plugin\Checkout;

use Magento\Catalog\Model\Product;
use Magento\Checkout\CustomerData\ItemPoolInterface;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductCard\Model\Card\QtyRuleResolver;
use Scr1be\HyvaProductCard\Model\Card\QtyRules;
use Scr1be\HyvaProductCard\Model\Config;
use Scr1be\HyvaProductCard\Plugin\Checkout\MinicartQtyRules;

class MinicartQtyRulesTest extends TestCase
{
    private QtyRuleResolver&MockObject $resolver;
    private Config&MockObject $config;
    private ItemPoolInterface&MockObject $subject;
    private MinicartQtyRules $plugin;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(QtyRuleResolver::class);
        $this->config = $this->createMock(Config::class);
        $this->subject = $this->createMock(ItemPoolInterface::class);
        $this->plugin = new MinicartQtyRules($this->resolver, $this->config);
    }

    public function testTheDrawerGetsTheSameRulesTheStepperGot(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->resolver->expects($this->once())
            ->method('resolve')
            ->with(31)
            ->willReturn(new QtyRules(12.0, 6.0, 60.0, false));

        $result = $this->plugin->afterGetItemData($this->subject, [], $this->item(31));

        $this->assertSame(
            ['min' => 12.0, 'step' => 6.0, 'max' => 60.0, 'is_decimal' => false],
            $result['qty_rules']
        );
    }

    public function testAnItemWithoutAProductIsSkippedRatherThanGuessedAt(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->resolver->expects($this->never())->method('resolve');

        $item = $this->createMock(Item::class);
        $item->method('getProduct')->willReturn(null);

        $this->assertSame(['qty' => 1], $this->plugin->afterGetItemData($this->subject, ['qty' => 1], $item));
    }

    public function testTheKillSwitchLeavesTheArrayAlone(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->resolver->expects($this->never())->method('resolve');

        $this->assertSame([], $this->plugin->afterGetItemData($this->subject, [], $this->item(31)));
    }

    private function item(int $productId): Item&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($productId);

        $item = $this->createMock(Item::class);
        $item->method('getProduct')->willReturn($product);

        return $item;
    }
}
