<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Media-relative path to public URL.
 */
class MediaUrl
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function forPath(string $mediaRelativePath): string
    {
        return $this->baseUrl() . $this->encodePath($mediaRelativePath);
    }

    /**
     * The same call Magento\Catalog\Model\Product\Media\Config::getBaseMediaUrl() makes. It is
     * store-scoped, which is why nothing above this class memoises a URL without the store id in
     * the key: two stores on one website routinely differ here, and a shared base URL would pin
     * every store to whichever one rendered first.
     */
    private function baseUrl(): string
    {
        return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
    }

    /**
     * Wysiwyg filenames come from an upload dialog, so spaces, ampersands and non-ASCII are normal
     * rather than exceptional. Segments are encoded individually because rawurlencode() would turn
     * the separators into %2F as well — and a srcset entry is space-delimited, so a raw space in a
     * URL there does not merely look untidy, it truncates the candidate at the space and makes the
     * remainder parse as the descriptor.
     */
    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
