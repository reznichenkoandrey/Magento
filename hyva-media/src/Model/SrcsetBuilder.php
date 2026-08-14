<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

use Magento\Store\Model\StoreManagerInterface;

/**
 * Turns a media path into a finished srcset payload.
 *
 * This is where the ladder is decided, and the decisions are the module: which rungs exist for this
 * particular source, in which order they are attempted, and whether the WebP set is complete enough
 * to offer at all.
 */
class SrcsetBuilder
{
    /**
     * A rung within a few percent of the source width is a re-encode wearing a smaller number: the
     * browser saves nothing worth the disk, and the derivative is the one most likely to come out
     * heavier than the original. Ten percent of headroom is enough that a 1600px upload still gets
     * its 1440 rung while a 1500px one does not.
     */
    private const MAX_RUNG_RATIO = 0.9;

    /** @var array<string, array{src: string, srcset: string, webp_srcset: string, width: int, height: int, mime_type: string}> */
    private array $memo = [];

    public function __construct(
        private readonly PathValidator $pathValidator,
        private readonly MediaStorage $storage,
        private readonly HeaderProbe $probe,
        private readonly Resizer $resizer,
        private readonly MediaUrl $mediaUrl,
        private readonly GdEncoder $encoder,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function build(string $rawPath, string $sizes): ?MediaImage
    {
        $storeId = (int) $this->storeManager->getStore()->getId();

        $path = $this->pathValidator->normalise($rawPath);
        if ($path === null) {
            return null;
        }

        // The store id belongs in the key because the media base URL is store-scoped: two stores on
        // one website share the derivative on disk but not the URL that reaches it.
        $key = $storeId . '|' . $path;
        if (!array_key_exists($key, $this->memo)) {
            $parts = $this->resolve($path, $storeId);
            if ($parts === null) {
                return null;
            }
            $this->memo[$key] = $parts;
        }

        $parts = $this->memo[$key];

        return new MediaImage(
            $parts['src'],
            $parts['srcset'],
            $parts['webp_srcset'],
            $sizes,
            $parts['width'],
            $parts['height'],
            $parts['mime_type']
        );
    }

    /**
     * @return array{src: string, srcset: string, webp_srcset: string, width: int, height: int, mime_type: string}|null
     */
    private function resolve(string $path, int $storeId): ?array
    {
        $stat = $this->storage->stat($path);
        if ($stat === null) {
            return null;
        }

        $dimensions = $this->probe->probe($path);
        if ($dimensions === null) {
            // A file that exists but whose header does not parse is not something to guess about:
            // without intrinsic dimensions there is no no-upscale rule, no megapixel ceiling, and
            // no width/height for the markup. The caller falls back to whatever it did before.
            return null;
        }

        $format = $this->encoder->formatForExtension($this->pathValidator->extensionOf($path));
        if ($format === null) {
            return null;
        }

        $originalUrl = $this->mediaUrl->forPath($path);
        $mimeType = $this->encoder->mimeTypeForFormat($format);

        if (!$this->config->isEnabled($storeId)) {
            // Switched off still means intrinsic dimensions: they are read from the header, they
            // cost nothing beyond the probe, and dropping them would hand back a payload that
            // reintroduces layout shift the moment an admin flips a toggle.
            return $this->payload($originalUrl, '', '', $dimensions, $mimeType);
        }

        $source = new SourceImage($path, $dimensions, $stat['mtime'], $stat['size'], $format);
        $rungs = $this->rungsFor($dimensions, $storeId);

        return $this->buildLadder($source, $rungs, $originalUrl, $mimeType, $storeId);
    }

    /**
     * @return int[] descending
     */
    private function rungsFor(ImageDimensions $dimensions, int $storeId): array
    {
        $ceiling = (int) floor($dimensions->width * self::MAX_RUNG_RATIO);

        $rungs = array_values(array_filter(
            $this->config->getWidths($storeId),
            static fn (int $width): bool => $width <= $ceiling
        ));

        if ($rungs === []) {
            // The source is smaller than the first configured rung. It does not get an upscale and
            // it does not get skipped either: its own width becomes the single rung, which costs
            // nothing in the source format (the original already is that rung) and still earns it a
            // WebP derivative, which for a small PNG is usually the larger of the two wins anyway.
            $rungs = [$dimensions->width];
        }

        // Widest first. The encode budget is spent in this order, so a request that runs out of it
        // gives up the rungs a browser only reaches on a narrow viewport rather than the one it
        // needs on a wide one.
        rsort($rungs);

        return $rungs;
    }

    /**
     * @param int[] $rungs
     * @return array{src: string, srcset: string, webp_srcset: string, width: int, height: int, mime_type: string}
     */
    private function buildLadder(
        SourceImage $source,
        array $rungs,
        string $originalUrl,
        string $mimeType,
        int $storeId
    ): array {
        $entries = [];
        $webpEntries = [];

        // A WebP source needs no WebP sibling — the primary ladder already is one, and a second
        // <source> repeating it would only cost the browser a decision.
        $webpWanted = $this->config->isWebpEnabled($storeId)
            && $source->format !== GdEncoder::FORMAT_WEBP
            && $this->encoder->isWebpSupported();

        foreach ($rungs as $rung) {
            $derivative = $this->resizer->derive($source, $rung, $source->format, $storeId);
            if ($derivative !== null) {
                $entries[$rung] = $this->mediaUrl->forPath($derivative);
            }

            if (!$webpWanted) {
                continue;
            }

            $webpDerivative = $this->resizer->derive($source, $rung, GdEncoder::FORMAT_WEBP, $storeId);
            if ($webpDerivative === null) {
                // All or nothing. A WebP set missing its widest rung would still win the format
                // negotiation in every browser that supports WebP, and then hand a narrow candidate
                // to a full-width slot — a visibly softer image than doing nothing at all. Stopping
                // here also spares the remaining rungs an encode whose output would be discarded.
                $webpWanted = false;
                $webpEntries = [];
                continue;
            }

            $webpEntries[$rung] = $this->mediaUrl->forPath($webpDerivative);
        }

        if ($entries === []) {
            // Nothing survived: an animated GIF, an unreadable source, or a budget already spent by
            // the images above this one on the page. The original at its real width is a true
            // single-candidate srcset, and the next render will have more to say.
            $entries = [$source->dimensions->width => $originalUrl];
        }

        $webpSrcset = $webpWanted && count($webpEntries) === count($rungs)
            ? $this->toSrcset($webpEntries)
            : '';

        return $this->payload(
            $this->largestUrl($entries),
            $this->toSrcset($entries),
            $webpSrcset,
            $source->dimensions,
            $mimeType
        );
    }

    /**
     * @param array<int, string> $entries
     */
    private function toSrcset(array $entries): string
    {
        ksort($entries);

        $candidates = [];
        foreach ($entries as $width => $url) {
            $candidates[] = $url . ' ' . $width . 'w';
        }

        return implode(', ', $candidates);
    }

    /**
     * Anything reaching the plain src attribute has no srcset support whatsoever — a crawler, a
     * feed reader, an email client that got the markup pasted into it. Handing that the widest
     * candidate is the reading that never shows a soft image; the byte cost lands only on clients
     * that were never going to participate in the ladder.
     *
     * @param array<int, string> $entries
     */
    private function largestUrl(array $entries): string
    {
        ksort($entries);

        return (string) end($entries);
    }

    /**
     * @return array{src: string, srcset: string, webp_srcset: string, width: int, height: int, mime_type: string}
     */
    private function payload(
        string $src,
        string $srcset,
        string $webpSrcset,
        ImageDimensions $dimensions,
        string $mimeType
    ): array {
        return [
            'src' => $src,
            'srcset' => $srcset,
            'webp_srcset' => $webpSrcset,
            'width' => $dimensions->width,
            'height' => $dimensions->height,
            'mime_type' => $mimeType,
        ];
    }
}
