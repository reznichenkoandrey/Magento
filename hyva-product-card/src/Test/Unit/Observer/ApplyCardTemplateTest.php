<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductCard\Observer\ApplyCardTemplate;
use Scr1be\HyvaProductCard\ViewModel\ProductCard;

/**
 * The observer exists because the layout instruction does not survive the theme, and the whole
 * failure mode is silence: the module installs, enables, passes every other test, and renders
 * Hyvä's stock card. So what is asserted here is narrow and literal — that the renderer block
 * comes out of this with this module's template on it.
 */
class ApplyCardTemplateTest extends TestCase
{
    private ProductCard&MockObject $cardViewModel;
    private ApplyCardTemplate $observer;

    protected function setUp(): void
    {
        $this->cardViewModel = $this->createMock(ProductCard::class);
        $this->observer = new ApplyCardTemplate($this->cardViewModel);
    }

    public function testAppliesTheCardTemplateToTheRendererBlock(): void
    {
        $this->cardViewModel->method('isEnabled')->willReturn(true);

        $renderer = $this->createMock(Template::class);
        $renderer->expects($this->once())
            ->method('setTemplate')
            ->with(ApplyCardTemplate::CARD_TEMPLATE);

        $this->observer->execute($this->eventFor($this->layoutWith($renderer)));
    }

    public function testLeavesTheStockCardInPlaceWhenTheModuleIsOff(): void
    {
        $this->cardViewModel->method('isEnabled')->willReturn(false);

        $renderer = $this->createMock(Template::class);
        $renderer->expects($this->never())->method('setTemplate');

        $this->observer->execute($this->eventFor($this->layoutWith($renderer)));
    }

    /**
     * Every page without a listing handle — most of them — reaches the observer with no renderer in
     * the layout at all.
     */
    public function testDoesNothingWhenThePageHasNoRendererBlock(): void
    {
        $this->cardViewModel->method('isEnabled')->willReturn(true);

        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->with(ApplyCardTemplate::RENDERER_BLOCK)->willReturn(false);

        $this->observer->execute($this->eventFor($layout));
    }

    public function testDoesNothingWhenTheEventCarriesNoLayout(): void
    {
        $this->cardViewModel->method('isEnabled')->willReturn(true);

        $this->observer->execute($this->eventFor(null));
    }

    private function layoutWith(Template $renderer): LayoutInterface&MockObject
    {
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->with(ApplyCardTemplate::RENDERER_BLOCK)->willReturn($renderer);

        return $layout;
    }

    private function eventFor(?LayoutInterface $layout): Observer
    {
        $event = $this->createMock(Observer::class);
        $event->method('getData')->with('layout')->willReturn($layout);

        return $event;
    }
}
