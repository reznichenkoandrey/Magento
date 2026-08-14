<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Test\Unit\Model;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMedia\Model\Config;
use Scr1be\HyvaMedia\Model\GdEncoder;
use Scr1be\HyvaMedia\Model\HeaderProbe;
use Scr1be\HyvaMedia\Model\ImageDimensions;
use Scr1be\HyvaMedia\Model\MediaStorage;
use Scr1be\HyvaMedia\Model\MediaUrl;
use Scr1be\HyvaMedia\Model\PathValidator;
use Scr1be\HyvaMedia\Model\Resizer;
use Scr1be\HyvaMedia\Model\SourceImage;
use Scr1be\HyvaMedia\Model\SrcsetBuilder;

/**
 * Where the ladder is decided. The rung filter, the descending order the encode budget is spent in,
 * and the all-or-nothing rule are all here, and none of them is visible from the markup they
 * eventually produce.
 */
class SrcsetBuilderTest extends TestCase
{
    private const LADDER = [320, 480, 768, 1024, 1440, 1920];

    private MediaStorage&MockObject $storage;
    private HeaderProbe&MockObject $probe;
    private Resizer&MockObject $resizer;
    private Config&MockObject $config;
    private SrcsetBuilder $builder;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(MediaStorage::class);
        $this->probe = $this->createMock(HeaderProbe::class);
        $this->resizer = $this->createMock(Resizer::class);
        $this->config = $this->createMock(Config::class);

        $this->storage->method('stat')->willReturn(['mtime' => 1700000000, 'size' => 250000]);
        $this->config->method('getWidths')->willReturn(self::LADDER);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isWebpEnabled')->willReturn(true);

        $this->rebuild();
    }

    public function testAnUnusablePathYieldsNothing(): void
    {
        $this->assertNull($this->builder->build('../../app/etc/env.php', '100vw'));
    }

    public function testAMissingFileYieldsNothing(): void
    {
        $this->storage = $this->createMock(MediaStorage::class);
        $this->storage->method('stat')->willReturn(null);
        $this->rebuild();

        $this->assertNull($this->builder->build('wysiwyg/gone.jpg', '100vw'));
    }

    public function testAnUnparseableHeaderYieldsNothing(): void
    {
        // Without intrinsic dimensions there is no no-upscale rule, no megapixel ceiling and no
        // width/height for the markup. Guessing would break all three at once.
        $this->probe->method('probe')->willReturn(null);

        $this->assertNull($this->builder->build('wysiwyg/broken.jpg', '100vw'));
    }

    public function testRungsWiderThanNinetyPercentOfTheSourceAreDropped(): void
    {
        // A 1600px source gets 1440 (90.0% exactly) but never 1920, which would be an upscale, and
        // the rungs it does get stay meaningfully smaller than the original.
        $this->probe->method('probe')->willReturn(new ImageDimensions(1600, 900));
        $this->resizer->method('derive')->willReturnCallback($this->passthroughDerive());

        $image = $this->builder->build('wysiwyg/a.jpg', '100vw');

        $this->assertNotNull($image);
        $this->assertSame([320, 480, 768, 1024, 1440], $this->widthsIn($image->srcset));
    }

    public function testTheSrcsetIsEmittedInAscendingWidthOrder(): void
    {
        $this->probe->method('probe')->willReturn(new ImageDimensions(4000, 2000));
        $this->resizer->method('derive')->willReturnCallback($this->passthroughDerive());

        $image = $this->builder->build('wysiwyg/a.jpg', '100vw');

        $this->assertNotNull($image);
        $this->assertSame(self::LADDER, $this->widthsIn($image->srcset));
    }

    public function testTheLadderIsWalkedWidestFirstSoATruncatedRenderKeepsItsLargeRungs(): void
    {
        // The encode budget is spent in this order. Ascending would spend it on the rungs a browser
        // only reaches on a narrow viewport and leave the wide one without a candidate.
        $requested = [];
        $this->probe->method('probe')->willReturn(new ImageDimensions(4000, 2000));
        $this->resizer->method('derive')->willReturnCallback(
            static function (SourceImage $source, int $width, string $format) use (&$requested): ?string {
                $requested[] = $width;

                return 'scr1be/media/' . $width . '/' . $source->path;
            }
        );

        $this->builder->build('wysiwyg/a.jpg', '100vw');

        $this->assertSame([1920, 1440, 1024, 768, 480, 320], array_values(array_unique($requested)));
    }

    public function testASourceSmallerThanTheFirstRungGetsItsOwnWidthAsTheSingleRung(): void
    {
        // No upscale, and no skipping either: the source format costs nothing at that rung because
        // the original already is it, and the WebP sibling is still worth having.
        $this->probe->method('probe')->willReturn(new ImageDimensions(300, 200));
        $this->resizer->method('derive')->willReturnCallback($this->passthroughDerive());

        $image = $this->builder->build('wysiwyg/small.jpg', '100vw');

        $this->assertNotNull($image);
        $this->assertSame([300], $this->widthsIn($image->srcset));
    }

    public function testIntrinsicDimensionsComeFromTheSourceNotTheTopRung(): void
    {
        // width/height are what stops layout shift, so they describe the image the markup is about,
        // not whichever derivative happened to be the largest.
        $this->probe->method('probe')->willReturn(new ImageDimensions(4000, 2250));
        $this->resizer->method('derive')->willReturnCallback($this->passthroughDerive());

        $image = $this->builder->build('wysiwyg/a.jpg', '100vw');

        $this->assertNotNull($image);
        $this->assertSame(4000, $image->width);
        $this->assertSame(2250, $image->height);
    }

    public function testTheFallbackSrcIsTheWidestCandidate(): void
    {
        $this->probe->method('probe')->willReturn(new ImageDimensions(4000, 2000));
        $this->resizer->method('derive')->willReturnCallback($this->passthroughDerive());

        $image = $this->builder->build('wysiwyg/a.jpg', '100vw');

        $this->assertNotNull($image);
        $this->assertSame('https://shop.test/media/scr1be/media/1920/wysiwyg/a.jpg', $image->src);
    }

    public function testACompleteWebpLadderIsOffered(): void
    {
        $this->probe->method('probe')->willReturn(new ImageDimensions(4000, 2000));
        $this->resizer->method('derive')->willReturnCallback($this->passthroughDerive());

        $image = $this->builder->build('wysiwyg/a.jpg', '100vw');

        $this->assertNotNull($image);
        $this->assertTrue($image->hasWebp());
        $this->assertSame(self::LADDER, $this->widthsIn($image->webpSrcset));
    }

    public function testOneMissingWebpRungDropsTheWholeWebpSet(): void
    {
        // A partial WebP set still wins the format negotiation in every browser that supports WebP,
        // and then hands a narrow candidate to a full-width slot — visibly softer than doing
        // nothing at all.
        $this->probe->method('probe')->willReturn(new ImageDimensions(4000, 2000));
        $this->resizer->method('derive')->willReturnCallback(
            static function (SourceImage $source, int $width, string $format): ?string {
                if ($format === GdEncoder::FORMAT_WEBP && $width === 768) {
                    return null;
                }

                return 'scr1be/media/' . $width . '/' . $source->path;
            }
        );

        $image = $this->builder->build('wysiwyg/a.jpg', '100vw');

        $this->assertNotNull($image);
        $this->assertFalse($image->hasWebp());
        $this->assertSame('', $image->webpSrcset);
        // The source-format ladder is unaffected: it is allowed to be sparse, WebP is not.
        $this->assertSame(self::LADDER, $this->widthsIn($image->srcset));
    }

    public function testAFailedWebpRungStopsFurtherWebpEncodes(): void
    {
        // Continuing would spend encode budget producing derivatives that are already discarded.
        $webpWidths = [];
        $this->probe->method('probe')->willReturn(new ImageDimensions(4000, 2000));
        $this->resizer->method('derive')->willReturnCallback(
            static function (SourceImage $source, int $width, string $format) use (&$webpWidths): ?string {
                if ($format === GdEncoder::FORMAT_WEBP) {
                    $webpWidths[] = $width;

                    return $width === 1440 ? null : 'scr1be/media/' . $width . '/' . $source->path;
                }

                return 'scr1be/media/' . $width . '/' . $source->path;
            }
        );

        $this->builder->build('wysiwyg/a.jpg', '100vw');

        $this->assertSame([1920, 1440], $webpWidths);
    }

    public function testAWebpSourceIsNotGivenAWebpSibling(): void
    {
        // The primary ladder already is WebP; a second <source> repeating it only costs the browser
        // a decision.
        $this->probe->method('probe')->willReturn(new ImageDimensions(2000, 1000));
        $this->resizer->method('derive')->willReturnCallback($this->passthroughDerive());

        $image = $this->builder->build('wysiwyg/a.webp', '100vw');

        $this->assertNotNull($image);
        $this->assertFalse($image->hasWebp());
        $this->assertSame('image/webp', $image->mimeType);
    }

    public function testWebpIsSkippedWhenGdCannotWriteIt(): void
    {
        $this->rebuild(webpSupported: false);
        $this->probe->method('probe')->willReturn(new ImageDimensions(4000, 2000));
        $this->resizer->method('derive')->willReturnCallback($this->passthroughDerive());

        $image = $this->builder->build('wysiwyg/a.jpg', '100vw');

        $this->assertNotNull($image);
        $this->assertFalse($image->hasWebp());
    }

    public function testEverythingRefusedFallsBackToTheOriginalAtItsRealWidth(): void
    {
        // An animated GIF, an unreadable source, or a budget already spent by the images above this
        // one. A single true candidate beats an empty attribute, and the next render has more.
        $this->probe->method('probe')->willReturn(new ImageDimensions(1200, 800));
        $this->resizer->method('derive')->willReturn(null);

        $image = $this->builder->build('wysiwyg/spinner.gif', '100vw');

        $this->assertNotNull($image);
        $this->assertSame('https://shop.test/media/wysiwyg/spinner.gif', $image->src);
        $this->assertSame([1200], $this->widthsIn($image->srcset));
    }

    public function testDisablingTheModuleKeepsIntrinsicDimensionsAndDropsTheLadder(): void
    {
        // Dropping width/height along with the derivatives would reintroduce layout shift the
        // moment an admin flips a toggle, which is a far more visible regression than larger bytes.
        $this->config = $this->createMock(Config::class);
        $this->config->method('isEnabled')->willReturn(false);
        $this->config->method('getWidths')->willReturn(self::LADDER);
        $this->rebuild();

        $this->probe->method('probe')->willReturn(new ImageDimensions(1600, 900));
        $this->resizer->expects($this->never())->method('derive');

        $image = $this->builder->build('wysiwyg/a.jpg', '50vw');

        $this->assertNotNull($image);
        $this->assertSame('https://shop.test/media/wysiwyg/a.jpg', $image->src);
        $this->assertSame('', $image->srcset);
        $this->assertSame('', $image->webpSrcset);
        $this->assertSame(1600, $image->width);
        $this->assertSame(900, $image->height);
    }

    public function testTheSizesAttributeIsCarriedThroughUntouched(): void
    {
        $this->probe->method('probe')->willReturn(new ImageDimensions(1600, 900));
        $this->resizer->method('derive')->willReturnCallback($this->passthroughDerive());

        $image = $this->builder->build('wysiwyg/a.jpg', '(max-width: 768px) 100vw, 50vw');

        $this->assertNotNull($image);
        $this->assertSame('(max-width: 768px) 100vw, 50vw', $image->sizes);
    }

    public function testTheLadderIsResolvedOncePerImagePerRequest(): void
    {
        // Two renders of the same banner on one page must not stat, probe and re-derive twice — and
        // must not disagree with each other when the first render was budget-truncated.
        $this->probe->expects($this->once())->method('probe')->willReturn(new ImageDimensions(1600, 900));
        $this->resizer->method('derive')->willReturnCallback($this->passthroughDerive());

        $first = $this->builder->build('wysiwyg/a.jpg', '100vw');
        $second = $this->builder->build('wysiwyg/a.jpg', '50vw');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->srcset, $second->srcset);
        // The memo holds the ladder, not the payload: sizes still varies per call.
        $this->assertSame('50vw', $second->sizes);
    }

    public function testTheSrcsetSyntaxIsUrlSpaceDescriptor(): void
    {
        $this->probe->method('probe')->willReturn(new ImageDimensions(600, 400));
        $this->resizer->method('derive')->willReturnCallback($this->passthroughDerive());

        $image = $this->builder->build('wysiwyg/a.jpg', '100vw');

        $this->assertNotNull($image);
        $this->assertSame(
            'https://shop.test/media/scr1be/media/320/wysiwyg/a.jpg 320w, '
            . 'https://shop.test/media/scr1be/media/480/wysiwyg/a.jpg 480w',
            $image->srcset
        );
    }

    /**
     * @return callable(SourceImage, int, string): string
     */
    private function passthroughDerive(): callable
    {
        return static fn (SourceImage $source, int $width, string $format): string
            => 'scr1be/media/' . $width . '/' . $source->path . ($format === GdEncoder::FORMAT_WEBP ? '.webp' : '');
    }

    /**
     * @return int[]
     */
    private function widthsIn(string $srcset): array
    {
        preg_match_all('/ (\d+)w/', $srcset, $matches);

        return array_map('intval', $matches[1]);
    }

    private function rebuild(bool $webpSupported = true): void
    {
        $encoder = $this->createMock(GdEncoder::class);
        $encoder->method('isWebpSupported')->willReturn($webpSupported);
        $encoder->method('formatForExtension')->willReturnCallback(
            static fn (string $extension): ?string => match ($extension) {
                'jpg', 'jpeg' => GdEncoder::FORMAT_JPEG,
                'png' => GdEncoder::FORMAT_PNG,
                'gif' => GdEncoder::FORMAT_GIF,
                'webp' => GdEncoder::FORMAT_WEBP,
                default => null,
            }
        );
        $encoder->method('mimeTypeForFormat')->willReturnCallback(
            static fn (string $format): string => match ($format) {
                GdEncoder::FORMAT_PNG => 'image/png',
                GdEncoder::FORMAT_GIF => 'image/gif',
                GdEncoder::FORMAT_WEBP => 'image/webp',
                default => 'image/jpeg',
            }
        );

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://shop.test/media/');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->builder = new SrcsetBuilder(
            new PathValidator(),
            $this->storage,
            $this->probe,
            $this->resizer,
            new MediaUrl($storeManager),
            $encoder,
            $this->config,
            $storeManager
        );
    }
}
