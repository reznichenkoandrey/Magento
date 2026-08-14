<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreClosure\Model\ClosureState;
use Scr1be\StoreClosure\Observer\HideAccountLinks;

class HideAccountLinksTest extends TestCase
{
    /**
     * @var ClosureState&MockObject
     */
    private $closureState;

    /**
     * @var LayoutInterface&MockObject
     */
    private $layout;

    private HideAccountLinks $observer;

    protected function setUp(): void
    {
        $this->closureState = $this->createMock(ClosureState::class);
        $this->layout = $this->createMock(LayoutInterface::class);
        $this->observer = new HideAccountLinks($this->closureState);
    }

    public function testAnOpenStoreKeepsItsAccountMenu(): void
    {
        $this->closureState->method('isCurrentStoreClosed')->willReturn(false);

        $this->layout->expects(self::never())->method('unsetElement');

        $this->observer->execute($this->observerWithLayout());
    }

    public function testAClosedStoreLosesTheAccountMenuAndTheLoginModal(): void
    {
        $this->closureState->method('isCurrentStoreClosed')->willReturn(true);
        $this->layout->method('hasElement')->willReturn(true);

        $removed = [];
        $this->layout->method('unsetElement')->willReturnCallback(
            function (string $name) use (&$removed): LayoutInterface {
                $removed[] = $name;

                return $this->layout;
            }
        );

        $this->observer->execute($this->observerWithLayout());

        self::assertSame(['header.customer', 'authentication-popup'], $removed);
    }

    public function testTheStoreSwitcherIsNotAmongTheBlocksRemoved(): void
    {
        // Deliberate asymmetry: a closed store is a dead end, and the switcher is the way out of
        // it. Removing it along with the rest of the header would strand the visitor.
        $this->closureState->method('isCurrentStoreClosed')->willReturn(true);
        $this->layout->method('hasElement')->willReturn(true);

        $this->layout->method('unsetElement')->willReturnCallback(
            function (string $name): LayoutInterface {
                self::assertStringNotContainsString('switcher', $name);

                return $this->layout;
            }
        );

        $this->observer->execute($this->observerWithLayout());
    }

    public function testABlockThatIsNotInTheLayoutIsNotTouched(): void
    {
        $this->closureState->method('isCurrentStoreClosed')->willReturn(true);
        $this->layout->method('hasElement')->willReturn(false);

        $this->layout->expects(self::never())->method('unsetElement');

        $this->observer->execute($this->observerWithLayout());
    }

    public function testAnEventWithoutALayoutIsIgnored(): void
    {
        $this->closureState->expects(self::never())->method('isCurrentStoreClosed');

        $event = $this->createMock(Event::class);
        $event->method('getData')->with('layout')->willReturn(null);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $this->observer->execute($observer);
    }

    private function observerWithLayout(): Observer
    {
        $event = $this->createMock(Event::class);
        $event->method('getData')->with('layout')->willReturn($this->layout);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}
