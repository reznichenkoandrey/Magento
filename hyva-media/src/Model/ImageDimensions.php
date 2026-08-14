<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

/**
 * Intrinsic pixel size of a source image, as read from its header.
 */
class ImageDimensions
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    public function megapixels(): float
    {
        return ($this->width * $this->height) / 1000000;
    }

    /**
     * Height that preserves the aspect ratio at the given width, never below one pixel — a 4000x30
     * banner scaled to 320 rounds to zero, and GD refuses a zero-height canvas.
     */
    public function heightFor(int $width): int
    {
        if ($this->width <= 0) {
            return 1;
        }

        return max(1, (int) round($this->height * ($width / $this->width)));
    }
}
