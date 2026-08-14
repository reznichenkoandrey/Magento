<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

/**
 * Everything the resizer needs to know about a source, gathered once per image per request.
 *
 * mtime and size are carried rather than re-read because both are consulted per rung and per
 * format — six rungs in two formats is twelve freshness comparisons and twelve size comparisons
 * against the same two numbers.
 */
class SourceImage
{
    public function __construct(
        public readonly string $path,
        public readonly ImageDimensions $dimensions,
        public readonly int $mtime,
        public readonly int $size,
        public readonly string $format,
    ) {
    }
}
