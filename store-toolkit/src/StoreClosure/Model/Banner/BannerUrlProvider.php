<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\Model\Banner;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * A URL for the closure banner that changes when the image does.
 *
 * The problem this solves is specific and expensive. Magento's cache tags stop at the edge of
 * Magento: a full page cache purge drops the HTML, but the image the HTML points at was fetched by
 * a CDN under its own URL and is held under that URL until its own TTL runs out. Replacing
 * `banner.png` with a different `banner.png` therefore does nothing for anybody who has already
 * been served the old one — and a closure banner is exactly the asset you replace in a hurry,
 * usually to correct a date or a phone number that is now wrong.
 *
 * So the served file is addressed by a hash of its own bytes. New bytes, new URL, and no cache
 * anywhere in the world has ever heard of it. The published copies are immutable and can be
 * cached forever, which is the same property upside down.
 */
class BannerUrlProvider
{
    /**
     * Where the config field's uploader writes. Matches the `upload_dir` in system.xml.
     */
    public const SOURCE_DIRECTORY = 'scr1be/closure';

    /**
     * Where the content-addressed copies live. A separate directory so that clearing it is safe:
     * every file in it can be rebuilt from the source on the next request.
     */
    public const PUBLISHED_DIRECTORY = 'scr1be/closure/content';

    private const HASH_ALGORITHM = 'sha256';

    /**
     * Half a SHA-256, in hex. 128 bits is far beyond what an accidental collision needs, and a
     * 64-character file name in a URL is unpleasant to read in a log.
     */
    private const HASH_LENGTH = 32;

    private const CACHE_KEY_PREFIX = 'scr1be_store_closure_banner_';

    /**
     * The hash is only recomputed when the source file's mtime moves, so the steady state costs
     * one cache read rather than a file read and a hash.
     */
    private const CACHE_LIFETIME = 86400;

    /**
     * The uploader accepts these; anything else never reaches the filesystem, and this list is the
     * second gate rather than the first.
     */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'gif', 'png'];

    private WriteInterface $mediaDirectory;

    private StoreManagerInterface $storeManager;

    private CacheInterface $cache;

    public function __construct(
        Filesystem $filesystem,
        StoreManagerInterface $storeManager,
        CacheInterface $cache
    ) {
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $this->storeManager = $storeManager;
        $this->cache = $cache;
    }

    /**
     * Absolute URL of the published banner, or null when there is nothing to show.
     *
     * @param string $storedValue The config value, e.g. `stores/2/closed.png` — the uploader
     *                            prepends the scope when the field declares `scope_info`.
     */
    public function getUrl(string $storedValue): ?string
    {
        $publishedPath = $this->getPublishedPath($storedValue);

        if ($publishedPath === null) {
            return null;
        }

        try {
            $store = $this->storeManager->getStore();
        } catch (NoSuchEntityException $e) {
            return null;
        }

        if (!$store instanceof Store) {
            return null;
        }

        return $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . $publishedPath;
    }

    /**
     * Media-relative path of the content-addressed copy, publishing it first if needed.
     */
    public function getPublishedPath(string $storedValue): ?string
    {
        $sourcePath = $this->getSourcePath($storedValue);

        if ($sourcePath === null) {
            return null;
        }

        try {
            if (!$this->mediaDirectory->isExist($sourcePath) || !$this->mediaDirectory->isFile($sourcePath)) {
                return null;
            }

            $cacheKey = self::CACHE_KEY_PREFIX . md5($sourcePath);
            $stat = $this->mediaDirectory->stat($sourcePath);
            $signature = (string) ($stat['mtime'] ?? '') . ':' . (string) ($stat['size'] ?? '');

            $cached = $this->cache->load($cacheKey);

            if (is_string($cached) && $cached !== '') {
                [$cachedSignature, $cachedPath] = array_pad(explode('|', $cached, 2), 2, '');

                if ($cachedSignature === $signature && $cachedPath !== '') {
                    return $cachedPath;
                }
            }

            $publishedPath = $this->publish($sourcePath, $storedValue);

            $this->cache->save($signature . '|' . $publishedPath, $cacheKey, [], self::CACHE_LIFETIME);

            return $publishedPath;
        } catch (FileSystemException $e) {
            // A missing or unreadable banner is a degraded closure page, not a broken storefront.
            return null;
        }
    }

    /**
     * @throws FileSystemException
     */
    private function publish(string $sourcePath, string $storedValue): string
    {
        $contents = $this->mediaDirectory->readFile($sourcePath);
        $hash = substr(hash(self::HASH_ALGORITHM, (string) $contents), 0, self::HASH_LENGTH);
        $publishedPath = self::PUBLISHED_DIRECTORY . '/' . $hash . '.' . $this->getExtension($storedValue);

        // Identical bytes produce an identical name, so a re-upload of the same image is a no-op
        // and the URL a CDN already holds stays valid.
        if (!$this->mediaDirectory->isExist($publishedPath)) {
            $this->mediaDirectory->writeFile($publishedPath, (string) $contents);
        }

        return $publishedPath;
    }

    /**
     * Null for anything that is not a plain relative path to an allowed image type. The stored
     * value is admin input that ends up in a filesystem read, so it is checked rather than trusted.
     */
    private function getSourcePath(string $storedValue): ?string
    {
        $value = trim($storedValue, '/');

        if ($value === '' || str_contains($value, '..') || preg_match('#^[A-Za-z0-9_\-./]+$#', $value) !== 1) {
            return null;
        }

        if (!in_array($this->getExtension($value), self::ALLOWED_EXTENSIONS, true)) {
            return null;
        }

        return self::SOURCE_DIRECTORY . '/' . $value;
    }

    private function getExtension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}
