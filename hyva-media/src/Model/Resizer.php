<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

/**
 * Produces one derivative: source plus a rung width plus a target format in, media-relative path of
 * something safe to serve out, or null.
 *
 * The whole module's promise — never upscale, never pad, never serve more bytes than the original —
 * is enforced here rather than at the point of use, because the point of use is a template and a
 * template cannot know how big the original was.
 */
class Resizer
{
    /**
     * Single-entry source cache. A page renders one image against six rungs in two formats, so the
     * same file would otherwise be read up to twelve times; a map keyed by path would instead hold
     * every image on the page in memory at once. One entry is the shape the access pattern actually
     * has.
     */
    private ?string $cachedPath = null;
    private ?string $cachedBytes = null;

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly DerivativePath $paths,
        private readonly GdEncoder $encoder,
        private readonly SkipMarker $skipMarker,
        private readonly EncodeBudget $budget,
        private readonly AnimatedImageDetector $animationDetector,
        private readonly Config $config,
    ) {
    }

    /**
     * @param string $format One of the GdEncoder::FORMAT_* constants
     * @return string|null Media-relative path to serve for this rung, or null when there is none
     */
    public function derive(SourceImage $source, int $width, string $format, ?int $storeId = null): ?string
    {
        $isWebp = $format === GdEncoder::FORMAT_WEBP;

        // The rung the ladder degenerates to for an image smaller than its first configured width.
        // Re-encoding a file into itself is pure loss: generation artefacts, a second copy on disk,
        // and a byte count that can only go up. The original is already this rung.
        if (!$isWebp && $width >= $source->dimensions->width) {
            return $source->path;
        }

        $target = $isWebp
            ? $this->paths->webpForWidth($source->path, $width)
            : $this->paths->forWidth($source->path, $width);

        // mtime invalidation rather than a content hash in the path. A hash would be self-
        // invalidating, but it moves the URL on every re-upload, and a moved URL is a cold CDN edge
        // and a cold browser cache for an image that in most re-uploads is visually the same.
        // Comparing against the source's mtime keeps the URL fixed and still refuses stale bytes.
        $derivativeMtime = $this->storage->mtime($target);
        if ($derivativeMtime !== null && $derivativeMtime >= $source->mtime) {
            return $target;
        }

        if ($isWebp && $this->skipMarker->isSet($source->path, $source->mtime)) {
            return null;
        }

        // Checked before the budget is touched: refusing a 60 MP source costs nothing and should
        // not consume an encode slot another image on the page could have used.
        if ($source->dimensions->megapixels() > $this->config->getMaxSourceMegapixels($storeId)) {
            $this->recordWebpFailure($isWebp, $source->path);

            return null;
        }

        if (!$this->budget->tryConsume($storeId)) {
            return null;
        }

        $bytes = $this->sourceBytes($source->path);
        if ($bytes === null) {
            // Unreadable is not the same verdict as unencodable: it is usually permissions or a
            // half-finished upload, both of which resolve without the source changing. No marker.
            return null;
        }

        if ($this->animationDetector->isAnimated($bytes, $source->format)) {
            // GD reads the first frame and writes a still image, reporting success. Dropping the
            // rung leaves the animation on the page at its original size, which is the only
            // outcome that is not a silent regression.
            $this->recordWebpFailure($isWebp, $source->path);

            return null;
        }

        $quality = $isWebp ? $this->config->getWebpQuality($storeId) : $this->config->getQuality($storeId);
        $encoded = $this->encoder->encode($bytes, $source->dimensions, $width, $format, $quality);

        if ($encoded === null) {
            $this->recordWebpFailure($isWebp, $source->path);

            return null;
        }

        if (strlen($encoded) >= $source->size) {
            return $this->handleFatterThanSource($isWebp, $source, $target, $bytes);
        }

        return $this->storage->write($target, $encoded) ? $target : null;
    }

    /**
     * A derivative can land heavier than the file it was made from — routinely, in fact, whenever
     * the upload was already run through a good encoder and GD's is merely adequate. Shipping it
     * would mean the module made the page slower, which is the one result it must never produce.
     */
    private function handleFatterThanSource(
        bool $isWebp,
        SourceImage $source,
        string $target,
        string $sourceBytes
    ): ?string {
        if (!$isWebp) {
            // The original bytes take the derivative's place at the derivative's URL. Keeping the
            // URL means the srcset does not have to describe this rung differently from any other,
            // and the browser gets an image at least as large as the slot it picked it for — more
            // pixels than advertised, fewer bytes than the alternative.
            return $this->storage->write($target, $sourceBytes) ? $target : null;
        }

        // The same trick is not available in WebP: the bytes behind a .webp URL inside a
        // <source type="image/webp"> have to actually be WebP. The rung is dropped, which under the
        // all-or-nothing rule drops the WebP set — and the marker is what stops the next render
        // re-deriving every other rung to reach the same conclusion.
        $this->skipMarker->set($source->path);

        return null;
    }

    private function recordWebpFailure(bool $isWebp, string $sourcePath): void
    {
        if ($isWebp) {
            $this->skipMarker->set($sourcePath);
        }
    }

    private function sourceBytes(string $path): ?string
    {
        if ($this->cachedPath !== $path) {
            $this->cachedPath = $path;
            // A null is cached too: a source that could not be read once in this request will not
            // become readable by the next rung.
            $this->cachedBytes = $this->storage->readAll($path);
        }

        return $this->cachedBytes;
    }
}
