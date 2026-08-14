<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

/**
 * What a template gets back: everything needed to write a <picture> or an <img>, and nothing that
 * would let a template make a decision this module has already made.
 *
 * The markup itself is deliberately absent. hyva-lazy-images owns the <picture> element, its
 * loading strategy and its placeholder; this payload is shaped to drop into that element, or into
 * six lines of hand-written markup, without either side importing the other's opinions.
 */
class MediaImage implements \JsonSerializable
{
    public function __construct(
        public readonly string $src,
        public readonly string $srcset,
        public readonly string $webpSrcset,
        public readonly string $sizes,
        public readonly int $width,
        public readonly int $height,
        public readonly string $mimeType,
    ) {
    }

    public function hasWebp(): bool
    {
        return $this->webpSrcset !== '';
    }

    public function hasSrcset(): bool
    {
        return $this->srcset !== '';
    }

    /**
     * @return array{
     *     src: string,
     *     srcset: string,
     *     webp_srcset: string,
     *     sizes: string,
     *     width: int,
     *     height: int,
     *     mime_type: string
     * }
     */
    public function toArray(): array
    {
        return [
            'src' => $this->src,
            'srcset' => $this->srcset,
            'webp_srcset' => $this->webpSrcset,
            'sizes' => $this->sizes,
            'width' => $this->width,
            'height' => $this->height,
            'mime_type' => $this->mimeType,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
