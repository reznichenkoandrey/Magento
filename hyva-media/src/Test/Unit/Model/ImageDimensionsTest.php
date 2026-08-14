<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMedia\Model\ImageDimensions;

class ImageDimensionsTest extends TestCase
{
    public function testHeightPreservesTheAspectRatio(): void
    {
        $this->assertSame(450, (new ImageDimensions(1600, 900))->heightFor(800));
    }

    public function testHeightRoundsRatherThanTruncates(): void
    {
        // Truncation biases every derivative one pixel short, which over a six-rung ladder is a
        // visibly drifting aspect ratio between the smallest rung and the largest.
        $this->assertSame(167, (new ImageDimensions(1000, 333))->heightFor(500));
    }

    public function testAnExtremeBannerNeverScalesToZeroHeight(): void
    {
        // GD refuses a zero-height canvas, so a 4000x30 banner at the 320 rung has to floor at one
        // pixel rather than fail the whole rung.
        $this->assertSame(1, (new ImageDimensions(4000, 30))->heightFor(100));
    }

    public function testMegapixelsAreReportedForTheCeilingCheck(): void
    {
        $this->assertEqualsWithDelta(8.0, (new ImageDimensions(4000, 2000))->megapixels(), 0.001);
    }
}
