<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductSlider\Model\Breakpoints;

class BreakpointsTest extends TestCase
{
    private Breakpoints $breakpoints;

    protected function setUp(): void
    {
        $this->breakpoints = new Breakpoints();
    }

    public function testEveryBreakpointIsPresentAfterNormalising(): void
    {
        $normalised = $this->breakpoints->normalise([Breakpoints::MOBILE => 2]);

        $this->assertSame(
            [Breakpoints::MOBILE, Breakpoints::TABLET, Breakpoints::DESKTOP, Breakpoints::WIDE],
            array_keys($normalised)
        );
    }

    public function testAMissingCountFallsBackToItsDefault(): void
    {
        $normalised = $this->breakpoints->normalise([]);

        $this->assertSame(1, $normalised[Breakpoints::MOBILE]);
        $this->assertSame(2, $normalised[Breakpoints::TABLET]);
        $this->assertSame(4, $normalised[Breakpoints::DESKTOP]);
        $this->assertSame(5, $normalised[Breakpoints::WIDE]);
    }

    public function testZeroSlidesIsClampedRatherThanRejected(): void
    {
        // A slider showing zero slides is a bug in the data, and refusing the whole save over it
        // helps nobody — the clamp has an obviously correct interpretation.
        $normalised = $this->breakpoints->normalise([Breakpoints::MOBILE => 0]);

        $this->assertSame(Breakpoints::MIN_SLIDES, $normalised[Breakpoints::MOBILE]);
    }

    public function testAnAbsurdCountIsClampedToTheCeiling(): void
    {
        $normalised = $this->breakpoints->normalise([Breakpoints::WIDE => 40]);

        $this->assertSame(Breakpoints::MAX_SLIDES, $normalised[Breakpoints::WIDE]);
    }

    public function testANonNumericCountFallsBackToTheDefault(): void
    {
        $normalised = $this->breakpoints->normalise([Breakpoints::DESKTOP => 'four']);

        $this->assertSame(4, $normalised[Breakpoints::DESKTOP]);
    }

    public function testTheWidestCountIsWhatDecidesWhetherAnythingCanScroll(): void
    {
        $widest = $this->breakpoints->getWidest([
            Breakpoints::MOBILE => 1,
            Breakpoints::TABLET => 2,
            Breakpoints::DESKTOP => 6,
            Breakpoints::WIDE => 3,
        ]);

        $this->assertSame(6, $widest);
    }

    public function testTheWidestCountIsMeasuredAfterNormalising(): void
    {
        // 99 would otherwise report a slider that can never scroll, and hide the arrows on a
        // perfectly ordinary carousel.
        $this->assertSame(
            Breakpoints::MAX_SLIDES,
            $this->breakpoints->getWidest([Breakpoints::DESKTOP => 99])
        );
    }

    public function testTheCssVariableNameMatchesTheStylesheet(): void
    {
        // `module.css` defines exactly these four; a rename on one side and not the other produces a
        // slider that silently keeps the default column count.
        $this->assertSame('--scr1be-slides-mobile', $this->breakpoints->getCssVariable(Breakpoints::MOBILE));
        $this->assertSame('--scr1be-slides-wide', $this->breakpoints->getCssVariable(Breakpoints::WIDE));
    }

    public function testEveryBreakpointMapsToAColumn(): void
    {
        foreach ($this->breakpoints->getCodes() as $code) {
            $this->assertNotNull($this->breakpoints->getColumn($code), $code);
        }
    }
}
