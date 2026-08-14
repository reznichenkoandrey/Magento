<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

/**
 * Turns whatever a template passed in into a media-relative path this module is willing to read,
 * or rejects it.
 *
 * The paths reaching this class are not always author-controlled: wysiwyg content is edited by
 * merchants, and a page builder field is one copy-paste away from carrying a path a developer never
 * wrote. Everything downstream — the probe, the encoder, the derivative writer — resolves paths
 * against the media directory, so containment has to be established once, here, before any of them
 * see a string.
 */
class PathValidator
{
    /**
     * Formats GD can be relied on to decode from a string. TIFF, BMP and SVG are deliberately out:
     * the first two are not universally compiled in, and SVG is not a raster format at all — it has
     * no derivative to produce and no business being handed to a rasteriser.
     */
    public const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * @return string|null Media-relative path, or null when the input is not usable
     */
    public function normalise(string $rawPath): ?string
    {
        // A null byte truncates the path at the OS layer while PHP still sees the full string, so
        // an extension check on the PHP string can pass for a file the OS opens under another name.
        if ($rawPath === '' || str_contains($rawPath, "\0")) {
            return null;
        }

        // Backslashes are not a separator on the platforms Magento runs on, which is exactly why a
        // traversal segment can hide behind one. Refuse rather than translate.
        if (str_contains($rawPath, '\\')) {
            return null;
        }

        $path = ltrim($rawPath, '/');

        // Anything with a scheme is a fetch, not a media path.
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $rawPath) === 1) {
            return null;
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        if (!in_array($this->extensionOf($path), self::SUPPORTED_EXTENSIONS, true)) {
            return null;
        }

        // Feeding a derivative back in as a source would key a derivative off a derivative: a second
        // generation loss, a path that grows a width segment per pass, and a cache that never
        // converges. The one-way rule is cheaper to enforce than to detect later.
        if (str_starts_with($path, DerivativePath::CACHE_ROOT . '/')) {
            return null;
        }

        return $path;
    }

    /**
     * @return string Lower-cased extension without the dot; empty string when there is none
     */
    public function extensionOf(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return strtolower(is_string($extension) ? $extension : '');
    }
}
