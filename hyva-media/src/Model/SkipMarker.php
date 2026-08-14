<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

/**
 * A zero-byte `.webp.skip` file recording that WebP encoding failed for a source.
 *
 * Without it a source GD cannot decode is re-attempted on every render, forever: the derivative is
 * never written, so the "does it exist" check always misses, so the encode always runs, so the
 * failure costs a full decode attempt per rung per page view. A cache that only remembers successes
 * is not a cache — it is a retry loop with a filesystem attached.
 *
 * The marker is keyed to the source rather than to a rung because every reason a WebP encode fails
 * is a property of the source: GD cannot decode it, it is animated, it exceeds the megapixel
 * ceiling. One marker check therefore short-circuits the whole ladder, which pairs exactly with
 * the all-or-nothing rule the WebP srcset follows anyway.
 *
 * mtime is the invalidator, as it is for the derivatives themselves: a marker older than the source
 * is a verdict on bytes that no longer exist. Re-uploading the image is all it takes to get a
 * fresh attempt.
 */
class SkipMarker
{
    public function __construct(
        private readonly MediaStorage $storage,
        private readonly DerivativePath $paths,
    ) {
    }

    public function isSet(string $sourcePath, int $sourceMtime): bool
    {
        $markerMtime = $this->storage->mtime($this->paths->webpSkipMarker($sourcePath));

        return $markerMtime !== null && $markerMtime >= $sourceMtime;
    }

    public function set(string $sourcePath): void
    {
        $this->storage->touch($this->paths->webpSkipMarker($sourcePath));
    }
}
