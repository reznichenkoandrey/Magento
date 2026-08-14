<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\Test\Unit\Plugin;

use Magento\Framework\Pricing\Render;
use Magento\Framework\Pricing\SaleableInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreClosure\Model\ClosureState;
use Scr1be\StoreClosure\Plugin\HidePriceRender;

class HidePriceRenderTest extends TestCase
{
    /**
     * @var ClosureState&MockObject
     */
    private $closureState;

    /**
     * @var Render&MockObject
     */
    private $subject;

    /**
     * @var SaleableInterface&MockObject
     */
    private $saleable;

    private HidePriceRender $plugin;

    protected function setUp(): void
    {
        $this->closureState = $this->createMock(ClosureState::class);
        $this->subject = $this->createMock(Render::class);
        $this->saleable = $this->createMock(SaleableInterface::class);
        $this->plugin = new HidePriceRender($this->closureState);
    }

    public function testCoreRendersNormallyWhenTheStoreIsOpen(): void
    {
        $this->closureState->method('shouldHidePrices')->willReturn(false);

        $proceed = static fn (string $code, SaleableInterface $item, array $args): string => '<span>$10</span>';

        self::assertSame(
            '<span>$10</span>',
            $this->plugin->aroundRender($this->subject, $proceed, 'final_price', $this->saleable, [])
        );
    }

    public function testTheWholeRenderIsSkippedWhenPricesAreHidden(): void
    {
        // Skipped, not discarded: an `after` plugin would have let core resolve the renderer pool
        // and run the price model first, which is the cost this exists to avoid.
        $this->closureState->method('shouldHidePrices')->willReturn(true);

        $called = false;
        $proceed = static function () use (&$called): string {
            $called = true;

            return '<span>$10</span>';
        };

        self::assertSame(
            '',
            $this->plugin->aroundRender($this->subject, $proceed, 'final_price', $this->saleable, [])
        );
        self::assertFalse($called, 'Core was still asked to render a price that is not shown.');
    }

    public function testTheArgumentsReachCoreUnchanged(): void
    {
        $this->closureState->method('shouldHidePrices')->willReturn(false);

        $seen = [];
        $proceed = static function (...$args) use (&$seen): string {
            $seen = $args;

            return '';
        };

        $this->plugin->aroundRender($this->subject, $proceed, 'tier_price', $this->saleable, ['zone' => 'item_list']);

        self::assertSame(['tier_price', $this->saleable, ['zone' => 'item_list']], $seen);
    }
}
