<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMedia\Model\AnimatedImageDetector;
use Scr1be\HyvaMedia\Model\GdEncoder;

class AnimatedImageDetectorTest extends TestCase
{
    private AnimatedImageDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new AnimatedImageDetector();
    }

    public function testAStillGifIsNotAnimated(): void
    {
        $bytes = 'GIF89a' . pack('vv', 100, 100) . $this->graphicControlExtension() . str_repeat("\x00", 32);

        $this->assertFalse($this->detector->isAnimated($bytes, GdEncoder::FORMAT_GIF));
    }

    public function testAMultiFrameGifIsAnimated(): void
    {
        // GD would hand back frame one and report success; the derivative would be a valid still
        // image of a thing that is supposed to move, which no test downstream would catch.
        $bytes = 'GIF89a' . pack('vv', 100, 100)
            . $this->graphicControlExtension()
            . str_repeat("\x00", 16)
            . $this->graphicControlExtension()
            . str_repeat("\x00", 16)
            . $this->graphicControlExtension();

        $this->assertTrue($this->detector->isAnimated($bytes, GdEncoder::FORMAT_GIF));
    }

    public function testAGifWithNoExtensionBlocksAtAllIsNotAnimated(): void
    {
        $bytes = 'GIF87a' . pack('vv', 100, 100) . str_repeat("\x42", 200);

        $this->assertFalse($this->detector->isAnimated($bytes, GdEncoder::FORMAT_GIF));
    }

    public function testAnAnimatedWebpIsDetectedFromItsAnimChunk(): void
    {
        $bytes = 'RIFF' . pack('V', 200) . 'WEBP' . 'VP8X' . pack('V', 10)
            . "\x12\x00\x00\x00" . str_repeat("\x00", 6)
            . 'ANIM' . pack('V', 6) . str_repeat("\x00", 6);

        $this->assertTrue($this->detector->isAnimated($bytes, GdEncoder::FORMAT_WEBP));
    }

    public function testAStillWebpIsNotAnimated(): void
    {
        $bytes = 'RIFF' . pack('V', 200) . 'WEBP' . 'VP8 ' . pack('V', 180) . str_repeat("\x00", 180);

        $this->assertFalse($this->detector->isAnimated($bytes, GdEncoder::FORMAT_WEBP));
    }

    public function testAnAnimChunkFarIntoTheFileIsIgnored(): void
    {
        // The chunk is only meaningful in the RIFF header. Scanning the whole file would let
        // compressed pixel data spelling "ANIM" veto derivatives for a perfectly still image.
        $bytes = 'RIFF' . pack('V', 5000) . 'WEBP' . 'VP8 ' . pack('V', 4980)
            . str_repeat("\x00", 200) . 'ANIM';

        $this->assertFalse($this->detector->isAnimated($bytes, GdEncoder::FORMAT_WEBP));
    }

    public function testFormatsWithoutMotionAreNeverAnimated(): void
    {
        $bytes = str_repeat("\x00\x21\xF9\x04abcd\x00\x2C", 5);

        $this->assertFalse($this->detector->isAnimated($bytes, GdEncoder::FORMAT_JPEG));
        $this->assertFalse($this->detector->isAnimated($bytes, GdEncoder::FORMAT_PNG));
    }

    private function graphicControlExtension(): string
    {
        return "\x00\x21\xF9\x04\x04\x0A\x00\x00\x00\x2C";
    }
}
