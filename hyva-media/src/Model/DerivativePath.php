<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

/**
 * Every path this module writes, in one place.
 *
 * The layout is width-keyed rather than hash-keyed on purpose. A hashed filename is opaque: when a
 * merchant asks why a banner looks soft, or an ops engineer wants to know how much disk the ladder
 * costs, the answer has to come from code. Under this layout `du -sh` per width directory answers
 * the second question and the first is a path you can read.
 */
class DerivativePath
{
    /** Single root so the whole cache is one `rm -rf` — including the skip markers. */
    public const CACHE_ROOT = 'scr1be/media';

    /**
     * Sibling of the numeric width directories. The leading dot cannot collide with a width, and
     * markers living outside the width tree is what makes a skip a property of the source rather
     * than of one rung.
     */
    private const SKIP_DIRECTORY = '.webp-skip';

    private const WEBP_SUFFIX = '.webp';
    private const SKIP_SUFFIX = '.skip';

    public function forWidth(string $sourcePath, int $width): string
    {
        return self::CACHE_ROOT . '/' . $width . '/' . $sourcePath;
    }

    /**
     * The WebP extension is appended, not substituted. `banner.jpg` and `banner.png` sitting in one
     * wysiwyg folder both reduce to `banner.webp` under substitution, and whichever renders second
     * silently serves the other one's pixels.
     */
    public function webpForWidth(string $sourcePath, int $width): string
    {
        return $this->forWidth($sourcePath, $width) . self::WEBP_SUFFIX;
    }

    public function webpSkipMarker(string $sourcePath): string
    {
        return self::CACHE_ROOT . '/' . self::SKIP_DIRECTORY . '/' . $sourcePath . self::WEBP_SUFFIX . self::SKIP_SUFFIX;
    }
}
