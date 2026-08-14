<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Test\Unit\Model\Card;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductCard\Model\Card\StockPresenter;
use Scr1be\HyvaProductCard\Model\Config;

class StockPresenterTest extends TestCase
{
    private Config&MockObject $config;
    private StockPresenter $presenter;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->presenter = new StockPresenter($this->config);
    }

    public function testAnUnknownQuantityIsNeverLowStock(): void
    {
        // A listing card reads a stock status index, not a quantity. Treating "no measurement" as
        // zero would put an urgency badge on every card in the catalogue.
        $this->config->method('isLowStockBadgeEnabled')->willReturn(true);
        $this->config->method('getLowStockThreshold')->willReturn(5.0);

        $presentation = $this->presenter->present(true, null);

        $this->assertFalse($presentation->isLow());
        $this->assertSame('In stock', $presentation->getLabel());
        $this->assertNull($presentation->getSalableQty());
    }

    public function testAQuantityAtTheThresholdCounts(): void
    {
        $this->config->method('isLowStockBadgeEnabled')->willReturn(true);
        $this->config->method('getLowStockThreshold')->willReturn(5.0);

        $this->assertTrue($this->presenter->present(true, 5.0)->isLow());
        $this->assertFalse($this->presenter->present(true, 5.5)->isLow());
    }

    public function testTheQuantityIsInTheSentenceAndNotOnlyInTheBadge(): void
    {
        $this->config->method('isLowStockBadgeEnabled')->willReturn(true);
        $this->config->method('getLowStockThreshold')->willReturn(5.0);

        // DECIMAL(12,4) trailing zeros are a database artefact, not a message.
        $this->assertSame('Only 3 left', $this->presenter->present(true, 3.0)->getLabel());
        $this->assertSame('Only 2.5 left', $this->presenter->present(true, 2.5)->getLabel());
    }

    public function testAZeroThresholdDisablesTheDecisionEntirely(): void
    {
        $this->config->method('isLowStockBadgeEnabled')->willReturn(true);
        $this->config->method('getLowStockThreshold')->willReturn(0.0);

        $this->assertFalse($this->presenter->present(true, 1.0)->isLow());
    }

    public function testOutOfStockIsNeverAlsoLowStock(): void
    {
        $this->config->method('isLowStockBadgeEnabled')->willReturn(true);
        $this->config->method('getLowStockThreshold')->willReturn(5.0);

        $presentation = $this->presenter->present(false, 2.0);

        $this->assertFalse($presentation->isInStock());
        $this->assertFalse($presentation->isLow());
        $this->assertSame('Out of stock', $presentation->getLabel());
    }

    public function testTheBadgeSwitchTurnsOffTheWholeUrgencyStory(): void
    {
        $this->config->method('isLowStockBadgeEnabled')->willReturn(false);
        $this->config->method('getLowStockThreshold')->willReturn(5.0);

        $presentation = $this->presenter->present(true, 1.0);

        $this->assertFalse($presentation->isLow());
        $this->assertSame('In stock', $presentation->getLabel());
    }
}
