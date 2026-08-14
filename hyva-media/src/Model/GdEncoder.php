<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

use Psr\Log\LoggerInterface;

/**
 * The module's seam to ext-gd: bytes in, bytes out, no filesystem.
 *
 * Magento's own GD adapter is not used, and cannot be. Magento\Framework\Image\Adapter\Gd2 maps
 * output callbacks per IMAGETYPE_* in a private static $_callbacks array holding exactly GIF, JPEG,
 * PNG, XBM and WBMP; _getCallback() throws InvalidArgumentException('Unsupported image format.')
 * for anything absent from it. WebP is absent, on both the create and the output side, so the
 * adapter can neither read nor write it. That single gap is why this class exists — the resize
 * itself the adapter would have handled fine.
 *
 * Working in strings rather than files is the second reason: the adapter's open() takes a path and
 * calls file_exists() on it, which is a local-filesystem assumption the media directory is not
 * entitled to make.
 */
class GdEncoder
{
    public const FORMAT_JPEG = 'jpeg';
    public const FORMAT_PNG = 'png';
    public const FORMAT_GIF = 'gif';
    public const FORMAT_WEBP = 'webp';

    /**
     * PNG's scale runs the other way from JPEG's: 0 is no compression, 9 is maximum. Level 9 is
     * lossless either way, so the only thing a lower level buys is encoder CPU, and this encoder
     * runs once per derivative and then never again.
     */
    private const PNG_COMPRESSION_LEVEL = 9;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isWebpSupported(): bool
    {
        return function_exists('imagewebp');
    }

    /**
     * Maps a validated file extension onto the format constants above.
     */
    public function formatForExtension(string $extension): ?string
    {
        return match ($extension) {
            'jpg', 'jpeg' => self::FORMAT_JPEG,
            'png' => self::FORMAT_PNG,
            'gif' => self::FORMAT_GIF,
            'webp' => self::FORMAT_WEBP,
            default => null,
        };
    }

    public function mimeTypeForFormat(string $format): string
    {
        return match ($format) {
            self::FORMAT_PNG => 'image/png',
            self::FORMAT_GIF => 'image/gif',
            self::FORMAT_WEBP => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /**
     * @param string $sourceBytes Whole source file
     * @param ImageDimensions $source Dimensions already read from the header
     * @param int $targetWidth Never above $source->width — the caller owns the no-upscale rule
     * @return string|null Encoded bytes, or null when GD refused at any step
     */
    public function encode(
        string $sourceBytes,
        ImageDimensions $source,
        int $targetWidth,
        string $format,
        int $quality
    ): ?string {
        if ($format === self::FORMAT_WEBP && !$this->isWebpSupported()) {
            return null;
        }

        $image = @imagecreatefromstring($sourceBytes);
        if ($image === false) {
            // CMYK JPEGs, 16-bit PNGs and truncated uploads all land here. The source stays on the
            // storefront untouched; the only thing lost is the derivative.
            $this->logger->debug('Scr1be_HyvaMedia: GD refused to decode a source image');

            return null;
        }

        try {
            $scaled = $targetWidth < $source->width
                ? $this->scale($image, $source, $targetWidth)
                : $image;

            if ($scaled === null) {
                return null;
            }

            $this->preserveAlpha($scaled);

            try {
                return $this->output($scaled, $format, $quality);
            } finally {
                // Only destroy the scaled copy when it is one; otherwise the outer finally would
                // free the same handle twice.
                if ($scaled !== $image) {
                    imagedestroy($scaled);
                }
            }
        } finally {
            imagedestroy($image);
        }
    }

    private function scale(\GdImage $image, ImageDimensions $source, int $targetWidth): ?\GdImage
    {
        $targetHeight = $source->heightFor($targetWidth);
        $canvas = @imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            $this->logger->warning('Scr1be_HyvaMedia: GD could not allocate a ' . $targetWidth . 'x' . $targetHeight . ' canvas');

            return null;
        }

        // A truecolor canvas starts opaque black. Copying a transparent source onto it with
        // blending on composites against that black instead of preserving the hole, which turns
        // every rounded-corner PNG logo into a black-cornered one.
        $this->preserveAlpha($canvas);

        $copied = imagecopyresampled(
            $canvas,
            $image,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            imagesx($image),
            imagesy($image)
        );

        if (!$copied) {
            imagedestroy($canvas);

            return null;
        }

        return $canvas;
    }

    /**
     * Blending off stops later writes compositing against the canvas; save-alpha is what makes
     * imagepng() and imagewebp() emit the alpha channel at all. GD loads a PNG with save-alpha
     * off, so the identity path — no resize, straight re-encode to WebP — needs this just as much
     * as the scaled one, and that is the path where a missing flag is easiest to overlook.
     */
    private function preserveAlpha(\GdImage $image): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

    /**
     * GD's writers take a file handle or a path and return a bool; there is no string form. Output
     * buffering is the standard way to capture them, and it is also what keeps this class free of
     * a temp-file dependency it would otherwise have to clean up on every error path.
     */
    private function output(\GdImage $image, string $format, int $quality): ?string
    {
        if (!ob_start()) {
            return null;
        }

        $written = false;
        try {
            $written = match ($format) {
                self::FORMAT_PNG => imagepng($image, null, self::PNG_COMPRESSION_LEVEL),
                self::FORMAT_GIF => imagegif($image),
                self::FORMAT_WEBP => imagewebp($image, null, $quality),
                default => imagejpeg($image, null, $quality),
            };
        } catch (\Throwable $e) {
            $this->logger->warning('Scr1be_HyvaMedia: GD encoder threw for ' . $format . ': ' . $e->getMessage());
        } finally {
            $bytes = ob_get_clean();
        }

        if (!$written || !is_string($bytes) || $bytes === '') {
            return null;
        }

        return $bytes;
    }
}
