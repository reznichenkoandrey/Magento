<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductCard\Model\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    public function testAnEmptySrcsetFieldFallsBackToAWorkingLadder(): void
    {
        // A card with no image ladder is a layout regression, so the fallback has to be a ladder.
        $this->givenValue('');

        $this->assertSame([240, 320, 480, 640], $this->config->getSrcsetWidths());
    }

    public function testSrcsetWidthsAreSortedDeduplicatedAndBounded(): void
    {
        $this->givenValue('640, 240,  240,12, 9000, 320');

        $this->assertSame([240, 320, 640], $this->config->getSrcsetWidths());
    }

    public function testTheLadderIsCappedSoTheMediaCacheDoesNot(): void
    {
        $this->givenValue('100,120,140,160,180,200,220,240');

        $this->assertCount(6, $this->config->getSrcsetWidths());
    }

    public function testTheSaleFloorIsClampedToAPercentage(): void
    {
        $this->givenValue('250');
        $this->assertSame(100.0, $this->config->getSaleMinPercent());

        $this->givenValue('-10');
        $this->assertSame(0.0, $this->config->getSaleMinPercent());
    }

    public function testNegativeCeilingsAndLifetimesReadAsZero(): void
    {
        $this->givenValue('-5');

        $this->assertSame(0, $this->config->getHoverImageCeiling());
        $this->assertSame(0, $this->config->getStockEndpointTtl());
        $this->assertSame(0.0, $this->config->getLowStockThreshold());
    }

    public function testFlagsGoThroughIsSetFlagSoYesNoStringsAreHandledByCore(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('scr1be_product_card/general/enabled', 'store', null)
            ->willReturn(true);

        $this->assertTrue($this->config->isEnabled());
    }

    private function givenValue(string $value): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->method('getValue')->willReturn($value);
        $this->config = new Config($this->scopeConfig);
    }
}
