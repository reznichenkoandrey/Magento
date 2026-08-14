<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Test\Unit\Model;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMedia\Model\AnimatedImageDetector;
use Scr1be\HyvaMedia\Model\Config;
use Scr1be\HyvaMedia\Model\DerivativePath;
use Scr1be\HyvaMedia\Model\EncodeBudget;
use Scr1be\HyvaMedia\Model\GdEncoder;
use Scr1be\HyvaMedia\Model\ImageDimensions;
use Scr1be\HyvaMedia\Model\MediaStorage;
use Scr1be\HyvaMedia\Model\Resizer;
use Scr1be\HyvaMedia\Model\SkipMarker;
use Scr1be\HyvaMedia\Model\SourceImage;

/**
 * The class that decides what actually gets served, and therefore the one holding every promise the
 * module makes: no upscale, no stale bytes, never more bytes than the original, never a silent
 * retry loop.
 */
class ResizerTest extends TestCase
{
    private const SOURCE_MTIME = 1700000000;
    private const SOURCE_SIZE = 120000;

    private MediaStorage&MockObject $storage;
    private GdEncoder&MockObject $encoder;
    private SkipMarker&MockObject $skipMarker;
    private EncodeBudget&MockObject $budget;
    private AnimatedImageDetector&MockObject $animation;
    private Config&MockObject $config;
    private Resizer $resizer;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(MediaStorage::class);
        $this->encoder = $this->createMock(GdEncoder::class);
        $this->skipMarker = $this->createMock(SkipMarker::class);
        $this->budget = $this->createMock(EncodeBudget::class);
        $this->animation = $this->createMock(AnimatedImageDetector::class);
        $this->config = $this->createMock(Config::class);

        $this->budget->method('tryConsume')->willReturn(true);
        $this->animation->method('isAnimated')->willReturn(false);
        $this->config->method('getMaxSourceMegapixels')->willReturn(40);
        $this->config->method('getQuality')->willReturn(82);
        $this->config->method('getWebpQuality')->willReturn(78);
        $this->skipMarker->method('isSet')->willReturn(false);

        $this->resizer = new Resizer(
            $this->storage,
            new DerivativePath(),
            $this->encoder,
            $this->skipMarker,
            $this->budget,
            $this->animation,
            $this->config
        );
    }

    public function testARungAtOrAboveTheSourceWidthIsTheSourceItself(): void
    {
        // No upscale, no re-encode, no second copy of the same pixels on disk. This is the rung the
        // ladder degenerates to for an image smaller than the first configured width.
        $this->storage->expects($this->never())->method('mtime');
        $this->encoder->expects($this->never())->method('encode');

        $this->assertSame(
            'wysiwyg/small.png',
            $this->resizer->derive($this->source('wysiwyg/small.png', 400, 300, GdEncoder::FORMAT_PNG), 400, GdEncoder::FORMAT_PNG)
        );
    }

    public function testAFreshDerivativeIsServedWithoutTouchingTheEncoder(): void
    {
        $this->storage->method('mtime')->willReturn(self::SOURCE_MTIME + 10);
        $this->encoder->expects($this->never())->method('encode');
        $this->budget->expects($this->never())->method('tryConsume');

        $this->assertSame(
            'scr1be/media/768/wysiwyg/a.jpg',
            $this->resizer->derive($this->source(), 768, GdEncoder::FORMAT_JPEG)
        );
    }

    public function testADerivativeOlderThanItsSourceIsRebuilt(): void
    {
        // mtime is the whole invalidation story. A derivative that predates the current upload is
        // the previous image, and the URL is deliberately identical for both.
        $this->storage->method('mtime')->willReturn(self::SOURCE_MTIME - 1);
        $this->storage->method('readAll')->willReturn('source-bytes');
        $this->encoder->expects($this->once())->method('encode')->willReturn('smaller');
        $this->storage->expects($this->once())
            ->method('write')
            ->with('scr1be/media/768/wysiwyg/a.jpg', 'smaller')
            ->willReturn(true);

        $this->assertSame(
            'scr1be/media/768/wysiwyg/a.jpg',
            $this->resizer->derive($this->source(), 768, GdEncoder::FORMAT_JPEG)
        );
    }

    public function testASetSkipMarkerShortCircuitsWebpBeforeAnyWork(): void
    {
        $this->skipMarker = $this->createMock(SkipMarker::class);
        $this->skipMarker->method('isSet')->willReturn(true);
        $this->rebuild();

        $this->storage->method('mtime')->willReturn(null);
        $this->budget->expects($this->never())->method('tryConsume');
        $this->encoder->expects($this->never())->method('encode');

        $this->assertNull($this->resizer->derive($this->source(), 768, GdEncoder::FORMAT_WEBP));
    }

    public function testASourceOverTheMegapixelCeilingIsRefusedWithoutSpendingBudget(): void
    {
        // Refusing costs nothing and must not take an encode slot away from an image on the same
        // page that could have used it.
        $this->storage->method('mtime')->willReturn(null);
        $this->budget->expects($this->never())->method('tryConsume');
        $this->encoder->expects($this->never())->method('encode');

        $huge = $this->source('wysiwyg/huge.jpg', 12000, 8000);

        $this->assertNull($this->resizer->derive($huge, 768, GdEncoder::FORMAT_JPEG));
    }

    public function testAnOversizedSourceRecordsASkipForWebpSoItIsNotRetried(): void
    {
        $this->storage->method('mtime')->willReturn(null);
        $this->skipMarker->expects($this->once())->method('set')->with('wysiwyg/huge.jpg');

        $this->resizer->derive($this->source('wysiwyg/huge.jpg', 12000, 8000), 768, GdEncoder::FORMAT_WEBP);
    }

    public function testAnExhaustedBudgetDropsTheRungWithoutRecordingAVerdict(): void
    {
        // Running out of budget says nothing about the image; a marker here would make a busy page
        // permanently disqualify whichever image happened to be last on it.
        $this->budget = $this->createMock(EncodeBudget::class);
        $this->budget->method('tryConsume')->willReturn(false);
        $this->rebuild();

        $this->storage->method('mtime')->willReturn(null);
        $this->skipMarker->expects($this->never())->method('set');
        $this->encoder->expects($this->never())->method('encode');

        $this->assertNull($this->resizer->derive($this->source(), 768, GdEncoder::FORMAT_WEBP));
    }

    public function testAnUnreadableSourceIsNotRecordedAsAWebpFailure(): void
    {
        // Permissions and half-finished uploads resolve without the source changing, so the mtime
        // that would invalidate a marker never moves — the skip would be permanent.
        $this->storage->method('mtime')->willReturn(null);
        $this->storage->method('readAll')->willReturn(null);
        $this->skipMarker->expects($this->never())->method('set');

        $this->assertNull($this->resizer->derive($this->source(), 768, GdEncoder::FORMAT_WEBP));
    }

    public function testAnAnimatedSourceIsLeftAlone(): void
    {
        // GD takes frame one and reports success. Dropping the rung leaves the animation on the
        // page at its original size, which is the only outcome that is not a silent regression.
        $this->animation = $this->createMock(AnimatedImageDetector::class);
        $this->animation->method('isAnimated')->willReturn(true);
        $this->rebuild();

        $this->storage->method('mtime')->willReturn(null);
        $this->storage->method('readAll')->willReturn('gif-bytes');
        $this->encoder->expects($this->never())->method('encode');

        $this->assertNull(
            $this->resizer->derive($this->source('wysiwyg/spinner.gif', 800, 600, GdEncoder::FORMAT_GIF), 320, GdEncoder::FORMAT_GIF)
        );
    }

    public function testARefusedEncodeRecordsASkipForWebp(): void
    {
        $this->storage->method('mtime')->willReturn(null);
        $this->storage->method('readAll')->willReturn('source-bytes');
        $this->encoder->method('encode')->willReturn(null);
        $this->skipMarker->expects($this->once())->method('set')->with('wysiwyg/a.jpg');

        $this->assertNull($this->resizer->derive($this->source(), 768, GdEncoder::FORMAT_WEBP));
    }

    public function testARefusedEncodeInTheSourceFormatRecordsNothing(): void
    {
        // There is no marker for the source-format ladder: the rung is simply absent from the
        // srcset, and the ladder is allowed to be sparse.
        $this->storage->method('mtime')->willReturn(null);
        $this->storage->method('readAll')->willReturn('source-bytes');
        $this->encoder->method('encode')->willReturn(null);
        $this->skipMarker->expects($this->never())->method('set');

        $this->assertNull($this->resizer->derive($this->source(), 768, GdEncoder::FORMAT_JPEG));
    }

    public function testAFatterDerivativeIsReplacedByTheOriginalBytesAtTheSameUrl(): void
    {
        // A source that already went through a good encoder routinely beats GD's output. Serving
        // the derivative would mean the module made the page slower; moving the URL would churn
        // every CDN edge for the rung. The bytes change, the URL does not.
        $this->storage->method('mtime')->willReturn(null);
        $this->storage->method('readAll')->willReturn('original-bytes');
        $this->encoder->method('encode')->willReturn(str_repeat('x', self::SOURCE_SIZE + 1));

        $this->storage->expects($this->once())
            ->method('write')
            ->with('scr1be/media/768/wysiwyg/a.jpg', 'original-bytes')
            ->willReturn(true);

        $this->assertSame(
            'scr1be/media/768/wysiwyg/a.jpg',
            $this->resizer->derive($this->source(), 768, GdEncoder::FORMAT_JPEG)
        );
    }

    public function testADerivativeExactlyTheSizeOfTheSourceCountsAsFatter(): void
    {
        // Equal bytes buy the browser nothing and cost the site a second file, so the tie goes to
        // the original.
        $this->storage->method('mtime')->willReturn(null);
        $this->storage->method('readAll')->willReturn('original-bytes');
        $this->encoder->method('encode')->willReturn(str_repeat('x', self::SOURCE_SIZE));

        $this->storage->expects($this->once())
            ->method('write')
            ->with($this->anything(), 'original-bytes')
            ->willReturn(true);

        $this->resizer->derive($this->source(), 768, GdEncoder::FORMAT_JPEG);
    }

    public function testAFatterWebpDropsTheRungAndRecordsASkip(): void
    {
        // The substitution trick is not available here: the bytes behind a .webp URL inside a
        // <source type="image/webp"> have to be WebP. The marker is what stops the next render
        // re-deriving every other rung to reach the same conclusion.
        $this->storage->method('mtime')->willReturn(null);
        $this->storage->method('readAll')->willReturn('original-bytes');
        $this->encoder->method('encode')->willReturn(str_repeat('x', self::SOURCE_SIZE + 1));

        $this->storage->expects($this->never())->method('write');
        $this->skipMarker->expects($this->once())->method('set')->with('wysiwyg/a.jpg');

        $this->assertNull($this->resizer->derive($this->source(), 768, GdEncoder::FORMAT_WEBP));
    }

    public function testAFailedWriteYieldsNoDerivative(): void
    {
        $this->storage->method('mtime')->willReturn(null);
        $this->storage->method('readAll')->willReturn('source-bytes');
        $this->encoder->method('encode')->willReturn('smaller');
        $this->storage->method('write')->willReturn(false);

        $this->assertNull($this->resizer->derive($this->source(), 768, GdEncoder::FORMAT_JPEG));
    }

    public function testTheSourceIsReadOnceForAWholeLadder(): void
    {
        // Six rungs in two formats is up to twelve reads of the same file per image. The single
        // -entry cache is what makes an on-demand resizer affordable on a cold page.
        $this->storage->method('mtime')->willReturn(null);
        $this->storage->expects($this->once())->method('readAll')->willReturn('source-bytes');
        $this->encoder->method('encode')->willReturn('smaller');
        $this->storage->method('write')->willReturn(true);

        $source = $this->source();
        foreach ([1440, 1024, 768, 480, 320] as $rung) {
            $this->resizer->derive($source, $rung, GdEncoder::FORMAT_JPEG);
            $this->resizer->derive($source, $rung, GdEncoder::FORMAT_WEBP);
        }
    }

    public function testWebpDerivativesGetTheirOwnQuality(): void
    {
        // Two separate ladders, two separate scales. Feeding the source-format quality to the WebP
        // encoder is the quiet way to make WebP stop being smaller.
        $this->storage->method('mtime')->willReturn(null);
        $this->storage->method('readAll')->willReturn('source-bytes');
        $this->storage->method('write')->willReturn(true);

        $qualities = [];
        $this->encoder->method('encode')->willReturnCallback(
            static function (string $bytes, ImageDimensions $dimensions, int $width, string $format, int $quality) use (&$qualities): string {
                $qualities[$format] = $quality;

                return 'small';
            }
        );

        $source = $this->source();
        $this->resizer->derive($source, 768, GdEncoder::FORMAT_JPEG);
        $this->resizer->derive($source, 768, GdEncoder::FORMAT_WEBP);

        $this->assertSame([GdEncoder::FORMAT_JPEG => 82, GdEncoder::FORMAT_WEBP => 78], $qualities);
    }

    public function testWebpAtTheIdentityRungIsStillEncoded(): void
    {
        // The source-format short-circuit does not apply: a small PNG's WebP sibling is usually the
        // larger of the two wins, and there is no upscale involved in re-encoding at 1:1.
        $this->storage->method('mtime')->willReturn(null);
        $this->storage->method('readAll')->willReturn('png-bytes');
        $this->encoder->expects($this->once())->method('encode')->willReturn('tiny');
        $this->storage->method('write')->willReturn(true);

        $this->assertSame(
            'scr1be/media/400/wysiwyg/small.png.webp',
            $this->resizer->derive(
                $this->source('wysiwyg/small.png', 400, 300, GdEncoder::FORMAT_PNG),
                400,
                GdEncoder::FORMAT_WEBP
            )
        );
    }

    private function source(
        string $path = 'wysiwyg/a.jpg',
        int $width = 1600,
        int $height = 900,
        string $format = GdEncoder::FORMAT_JPEG
    ): SourceImage {
        return new SourceImage(
            $path,
            new ImageDimensions($width, $height),
            self::SOURCE_MTIME,
            self::SOURCE_SIZE,
            $format
        );
    }

    /**
     * Some cases need a collaborator to answer differently from the setUp default. Replacing the
     * mock and re-wiring is the only way to get there: a second ->method() on the existing mock
     * registers a matcher the first one never lets run.
     */
    private function rebuild(): void
    {
        $this->resizer = new Resizer(
            $this->storage,
            new DerivativePath(),
            $this->encoder,
            $this->skipMarker,
            $this->budget,
            $this->animation,
            $this->config
        );
    }
}
