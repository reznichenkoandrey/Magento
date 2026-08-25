<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\HyvaMedia\Model\GdEncoder;
use Scr1be\HyvaMedia\Model\ImageDimensions;

/**
 * Run against real GD rather than a mock of it. The point of this class is the contract with an
 * extension, and a mocked extension proves only that the module agrees with itself — which is
 * exactly the assumption that put Magento's own adapter out of the running here.
 */
class GdEncoderTest extends TestCase
{
    private GdEncoder $encoder;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required to exercise the encoder seam');
        }

        $this->encoder = new GdEncoder($this->createMock(LoggerInterface::class));
    }

    public function testCoreAdapterCannotDoWhatThisClassExistsFor(): void
    {
        // The premise of the whole class, asserted rather than assumed: Gd2's private $_callbacks
        // map has no IMAGETYPE_WEBP entry, so _getCallback() throws for it on both create and
        // output. If a future Magento adds one, this test is where that shows up.
        $callbacks = new \ReflectionProperty(\Magento\Framework\Image\Adapter\Gd2::class, '_callbacks');

        $this->assertArrayNotHasKey(IMAGETYPE_WEBP, $callbacks->getValue());
    }

    public function testAJpegIsScaledToTheRequestedWidth(): void
    {
        $source = new ImageDimensions(400, 200);

        $encoded = $this->encoder->encode(
            $this->jpegBytes(400, 200),
            $source,
            100,
            GdEncoder::FORMAT_JPEG,
            80
        );

        $this->assertNotNull($encoded);
        $this->assertSame([100, 50], $this->sizeOf($encoded));
    }

    public function testTheAspectRatioIsPreservedOnOddDimensions(): void
    {
        $encoded = $this->encoder->encode(
            $this->jpegBytes(333, 111),
            new ImageDimensions(333, 111),
            100,
            GdEncoder::FORMAT_JPEG,
            80
        );

        $this->assertNotNull($encoded);
        $this->assertSame([100, 33], $this->sizeOf($encoded));
    }

    public function testAWidthAtOrAboveTheSourceIsReEncodedRatherThanUpscaled(): void
    {
        // The identity rung. Asking for 800 from a 400px source must not produce an 800px image —
        // upscaling adds bytes and removes nothing.
        $encoded = $this->encoder->encode(
            $this->jpegBytes(400, 200),
            new ImageDimensions(400, 200),
            800,
            GdEncoder::FORMAT_JPEG,
            80
        );

        $this->assertNotNull($encoded);
        $this->assertSame([400, 200], $this->sizeOf($encoded));
    }

    public function testWebpIsProducedWhereMagentosAdapterWouldThrow(): void
    {
        if (!$this->encoder->isWebpSupported()) {
            $this->markTestSkipped('GD was built without WebP support');
        }

        $encoded = $this->encoder->encode(
            $this->jpegBytes(400, 200),
            new ImageDimensions(400, 200),
            200,
            GdEncoder::FORMAT_WEBP,
            75
        );

        $this->assertNotNull($encoded);
        $this->assertSame('image/webp', $this->mimeOf($encoded));
        $this->assertSame([200, 100], $this->sizeOf($encoded));
    }

    public function testAlphaSurvivesAResizeToPng(): void
    {
        $encoded = $this->encoder->encode(
            $this->transparentPngBytes(200, 200),
            new ImageDimensions(200, 200),
            100,
            GdEncoder::FORMAT_PNG,
            82
        );

        $this->assertNotNull($encoded);
        $this->assertTrue($this->isTopLeftTransparent($encoded));
    }

    public function testAlphaSurvivesTheIdentityPathToWebp(): void
    {
        // The path with no resize on it: GD loads a PNG with save-alpha off, so without an explicit
        // flag the straight re-encode is the one that quietly fills every transparent pixel black.
        if (!$this->encoder->isWebpSupported()) {
            $this->markTestSkipped('GD was built without WebP support');
        }

        $encoded = $this->encoder->encode(
            $this->transparentPngBytes(120, 120),
            new ImageDimensions(120, 120),
            120,
            GdEncoder::FORMAT_WEBP,
            80
        );

        $this->assertNotNull($encoded);
        $this->assertTrue($this->isTopLeftTransparent($encoded));
    }

    public function testUndecodableBytesReturnNullRatherThanThrowing(): void
    {
        $encoded = $this->encoder->encode(
            'this is not an image',
            new ImageDimensions(100, 100),
            50,
            GdEncoder::FORMAT_JPEG,
            80
        );

        $this->assertNull($encoded);
    }

    public function testFormatMappingCoversTheValidatedExtensions(): void
    {
        $this->assertSame(GdEncoder::FORMAT_JPEG, $this->encoder->formatForExtension('jpg'));
        $this->assertSame(GdEncoder::FORMAT_JPEG, $this->encoder->formatForExtension('jpeg'));
        $this->assertSame(GdEncoder::FORMAT_PNG, $this->encoder->formatForExtension('png'));
        $this->assertSame(GdEncoder::FORMAT_GIF, $this->encoder->formatForExtension('gif'));
        $this->assertSame(GdEncoder::FORMAT_WEBP, $this->encoder->formatForExtension('webp'));
        $this->assertNull($this->encoder->formatForExtension('svg'));
    }

    public function testMimeTypesMatchTheFormats(): void
    {
        // These land in a <source type="…"> attribute, where a wrong value means the browser skips
        // the candidate entirely rather than failing visibly.
        $this->assertSame('image/jpeg', $this->encoder->mimeTypeForFormat(GdEncoder::FORMAT_JPEG));
        $this->assertSame('image/png', $this->encoder->mimeTypeForFormat(GdEncoder::FORMAT_PNG));
        $this->assertSame('image/gif', $this->encoder->mimeTypeForFormat(GdEncoder::FORMAT_GIF));
        $this->assertSame('image/webp', $this->encoder->mimeTypeForFormat(GdEncoder::FORMAT_WEBP));
    }

    public function testTheOutputBufferIsLeftAsItWasFound(): void
    {
        // Capturing GD's output means opening a buffer per encode. One that is not closed on every
        // path would swallow the rest of the page's HTML.
        $depth = ob_get_level();

        $this->encoder->encode($this->jpegBytes(80, 80), new ImageDimensions(80, 80), 40, GdEncoder::FORMAT_JPEG, 80);
        $this->encoder->encode('not an image', new ImageDimensions(80, 80), 40, GdEncoder::FORMAT_JPEG, 80);

        $this->assertSame($depth, ob_get_level());
    }

    private function jpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 40, 40));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function transparentPngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocatealpha($image, 0, 0, 0, 127));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function sizeOf(string $bytes): array
    {
        $image = imagecreatefromstring($bytes);
        $size = [imagesx($image), imagesy($image)];
        imagedestroy($image);

        return $size;
    }

    private function mimeOf(string $bytes): string
    {
        $info = getimagesizefromstring($bytes);

        // `getimagesizefromstring()` returns false for anything it cannot read; when it returns an
        // array, `mime` is always in it.
        return is_array($info) ? (string) $info['mime'] : '';
    }

    private function isTopLeftTransparent(string $bytes): bool
    {
        $image = imagecreatefromstring($bytes);
        $colour = imagecolorat($image, 0, 0);
        $alpha = ($colour >> 24) & 0x7F;
        imagedestroy($image);

        return $alpha > 0;
    }
}
