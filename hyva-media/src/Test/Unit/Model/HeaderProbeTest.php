<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMedia\Model\HeaderProbe;
use Scr1be\HyvaMedia\Model\MediaStorage;

/**
 * The parsers are exercised against hand-built headers rather than real image files. A fixture
 * produced by GD would only prove the probe agrees with GD on the four formats GD happens to emit;
 * bytes assembled here can also express the cases that break it — a JPEG whose frame header hides
 * behind a 40 KB EXIF block, a lossless WebP, a truncated PNG.
 */
class HeaderProbeTest extends TestCase
{
    private HeaderProbe $probe;

    protected function setUp(): void
    {
        $this->probe = new HeaderProbe($this->createMock(MediaStorage::class));
    }

    public function testPngDimensionsComeFromTheIhdrChunk(): void
    {
        $dimensions = $this->probe->probeBytes($this->png(1440, 810));

        $this->assertNotNull($dimensions);
        $this->assertSame(1440, $dimensions->width);
        $this->assertSame(810, $dimensions->height);
    }

    public function testPngWithoutAnIhdrChunkIsRefused(): void
    {
        // A PNG signature followed by any other chunk is not a PNG this module can size; guessing
        // at offsets 16 and 20 would return whatever that chunk happens to hold.
        $bytes = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'sRGB' . pack('NN', 1440, 810);

        $this->assertNull($this->probe->probeBytes($bytes));
    }

    public function testTruncatedPngHeaderIsRefused(): void
    {
        $this->assertNull($this->probe->probeBytes(substr($this->png(800, 600), 0, 18)));
    }

    public function testGifDimensionsComeFromTheLogicalScreenDescriptor(): void
    {
        $dimensions = $this->probe->probeBytes($this->gif(320, 240));

        $this->assertNotNull($dimensions);
        $this->assertSame(320, $dimensions->width);
        $this->assertSame(240, $dimensions->height);
    }

    public function testGif87aIsAcceptedAlongsideGif89a(): void
    {
        $bytes = 'GIF87a' . pack('vv', 100, 50);

        $dimensions = $this->probe->probeBytes($bytes);

        $this->assertNotNull($dimensions);
        $this->assertSame(100, $dimensions->width);
        $this->assertSame(50, $dimensions->height);
    }

    public function testJpegDimensionsComeFromTheStartOfFrame(): void
    {
        $dimensions = $this->probe->probeBytes($this->jpeg(1920, 1080));

        $this->assertNotNull($dimensions);
        $this->assertSame(1920, $dimensions->width);
        $this->assertSame(1080, $dimensions->height);
    }

    public function testJpegHeightIsReadBeforeWidth(): void
    {
        // Every other format here is width-first. A transposed read produces a plausible pair of
        // numbers that silently inverts the aspect ratio of every portrait image on the site.
        $dimensions = $this->probe->probeBytes($this->jpeg(600, 1200));

        $this->assertNotNull($dimensions);
        $this->assertSame(600, $dimensions->width);
        $this->assertSame(1200, $dimensions->height);
    }

    public function testJpegFrameHeaderIsFoundBehindALargeExifSegment(): void
    {
        // This is the case the 64 KB read budget exists for: a camera JPEG carries EXIF and an
        // embedded thumbnail before the frame header.
        $exif = $this->jpegSegment(0xE1, str_repeat("\x00", 48000));

        $dimensions = $this->probe->probeBytes($this->jpeg(2400, 1600, $exif));

        $this->assertNotNull($dimensions);
        $this->assertSame(2400, $dimensions->width);
        $this->assertSame(1600, $dimensions->height);
    }

    public function testProgressiveJpegIsSized(): void
    {
        // SOF2 rather than SOF0. Progressive is the default of most export pipelines, so a probe
        // that only knows the baseline marker would fail on the majority of real uploads.
        $bytes = "\xFF\xD8" . $this->jpegSegment(0xC2, "\x08" . pack('nn', 500, 900) . "\x03");

        $dimensions = $this->probe->probeBytes($bytes);

        $this->assertNotNull($dimensions);
        $this->assertSame(900, $dimensions->width);
        $this->assertSame(500, $dimensions->height);
    }

    public function testJpegHuffmanTableIsNotMistakenForAFrameHeader(): void
    {
        // 0xC4 sits inside the 0xC0-0xCF run but is a Huffman table, not a frame. Reading its body
        // as dimensions yields numbers, which is precisely why the exclusion has to be explicit.
        $huffman = $this->jpegSegment(0xC4, str_repeat("\x11", 20));

        $dimensions = $this->probe->probeBytes($this->jpeg(800, 400, $huffman));

        $this->assertNotNull($dimensions);
        $this->assertSame(800, $dimensions->width);
        $this->assertSame(400, $dimensions->height);
    }

    public function testJpegWithoutAFrameHeaderBeforeTheScanIsRefused(): void
    {
        $bytes = "\xFF\xD8" . $this->jpegSegment(0xE0, 'JFIF' . "\x00") . "\xFF\xDA";

        $this->assertNull($this->probe->probeBytes($bytes));
    }

    public function testJpegRestartMarkersAreSkippedWithoutALengthField(): void
    {
        // Standalone markers have no length. Reading two bytes of the next segment as one would
        // desynchronise the walk and land the parser in pixel data.
        $bytes = "\xFF\xD8\xFF\xD0\xFF\xD7" . $this->jpegSegment(0xC0, "\x08" . pack('nn', 120, 240) . "\x03");

        $dimensions = $this->probe->probeBytes($bytes);

        $this->assertNotNull($dimensions);
        $this->assertSame(240, $dimensions->width);
        $this->assertSame(120, $dimensions->height);
    }

    public function testLossyWebpIsSized(): void
    {
        $dimensions = $this->probe->probeBytes($this->webpLossy(640, 480));

        $this->assertNotNull($dimensions);
        $this->assertSame(640, $dimensions->width);
        $this->assertSame(480, $dimensions->height);
    }

    public function testLossyWebpWithoutItsSyncCodeIsRefused(): void
    {
        $bytes = substr_replace($this->webpLossy(640, 480), "\x00\x00\x00", 23, 3);

        $this->assertNull($this->probe->probeBytes($bytes));
    }

    public function testLosslessWebpIsSized(): void
    {
        // VP8L stores width-1 and height-1 as adjacent 14-bit fields, so an off-by-one here is a
        // whole class of wrong aspect ratios that only shows up on odd dimensions.
        $dimensions = $this->probe->probeBytes($this->webpLossless(333, 777));

        $this->assertNotNull($dimensions);
        $this->assertSame(333, $dimensions->width);
        $this->assertSame(777, $dimensions->height);
    }

    public function testExtendedWebpIsSized(): void
    {
        $dimensions = $this->probe->probeBytes($this->webpExtended(2048, 1152));

        $this->assertNotNull($dimensions);
        $this->assertSame(2048, $dimensions->width);
        $this->assertSame(1152, $dimensions->height);
    }

    public function testUnknownWebpChunkIsRefused(): void
    {
        $bytes = 'RIFF' . pack('V', 100) . 'WEBP' . 'ALPH' . str_repeat("\x00", 40);

        $this->assertNull($this->probe->probeBytes($bytes));
    }

    public function testZeroDimensionsAreRefused(): void
    {
        // A parse that "succeeds" with a zero would divide by zero when the ladder is filtered, and
        // GD refuses a zero-sized canvas anyway.
        $this->assertNull($this->probe->probeBytes($this->png(0, 600)));
    }

    public function testUnrecognisedBytesAreRefused(): void
    {
        $this->assertNull($this->probe->probeBytes('<?xml version="1.0"?><svg width="10"/>'));
    }

    public function testEmptyInputIsRefused(): void
    {
        $this->assertNull($this->probe->probeBytes(''));
    }

    private function png(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $width, $height) . "\x08\x06\x00\x00\x00";
    }

    private function gif(int $width, int $height): string
    {
        return 'GIF89a' . pack('vv', $width, $height) . "\xF7\x00\x00";
    }

    /**
     * @param string $before Raw segments to place between the SOI and the frame header
     */
    private function jpeg(int $width, int $height, string $before = ''): string
    {
        return "\xFF\xD8" . $before . $this->jpegSegment(
            0xC0,
            "\x08" . pack('nn', $height, $width) . "\x03"
        );
    }

    private function jpegSegment(int $marker, string $body): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($body) + 2) . $body;
    }

    private function webpLossy(int $width, int $height): string
    {
        return 'RIFF' . pack('V', 100) . 'WEBP' . 'VP8 ' . pack('V', 80)
            . "\x00\x00\x00"
            . "\x9D\x01\x2A"
            . pack('vv', $width, $height);
    }

    private function webpLossless(int $width, int $height): string
    {
        $packed = ($width - 1) | (($height - 1) << 14);

        return 'RIFF' . pack('V', 100) . 'WEBP' . 'VP8L' . pack('V', 80)
            . "\x2F"
            . pack('V', $packed);
    }

    private function webpExtended(int $width, int $height): string
    {
        return 'RIFF' . pack('V', 100) . 'WEBP' . 'VP8X' . pack('V', 10)
            . "\x10\x00\x00\x00"
            . substr(pack('V', $width - 1), 0, 3)
            . substr(pack('V', $height - 1), 0, 3);
    }
}
