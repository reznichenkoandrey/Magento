<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model\Card;

/**
 * A card image, already resolved down to the attributes an `<img>` needs.
 *
 * `width`/`height` are the *intrinsic* dimensions of the `src` rung and exist so the element can
 * reserve its box before the bytes arrive — the difference between a grid that settles and a grid
 * that shoves itself around while images decode.
 */
class ImageSource implements \JsonSerializable
{
    /**
     * @param string $srcset Ready-to-emit `url 240w, url 320w, …`; empty when only one rung exists.
     */
    public function __construct(
        private readonly string $url,
        private readonly int $width,
        private readonly int $height,
        private readonly string $label,
        private readonly string $srcset,
        private readonly string $sizes,
        private readonly ?string $hoverUrl
    ) {
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getSrcset(): string
    {
        return $this->srcset;
    }

    public function getSizes(): string
    {
        return $this->sizes;
    }

    public function getHoverUrl(): ?string
    {
        return $this->hoverUrl;
    }

    /**
     * @return array{url: string, width: int, height: int, label: string, srcset: string, sizes: string, hover_url: string|null}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'width' => $this->width,
            'height' => $this->height,
            'label' => $this->label,
            'srcset' => $this->srcset,
            'sizes' => $this->sizes,
            'hover_url' => $this->hoverUrl,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
