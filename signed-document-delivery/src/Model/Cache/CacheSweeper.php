<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Cache;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Psr\Log\LoggerInterface;

/**
 * Deletes rendered PDFs that nothing has asked for in a while.
 *
 * The cache has no index, so there is nothing to walk but the directory itself. That is the price
 * of a content-addressed store and it is a fair one: the sweep is the only thing that ever needs
 * the listing, it runs hourly, and the two-level shard keeps each `readdir` small.
 *
 * Age is read from mtime rather than from anything inside the file. `rename()` preserves the source
 * file's mtime, so a rewritten entry — same key, freshly rendered — is correctly treated as new.
 * Reads do *not* refresh it: this is an expiry, not an LRU, which means a document downloaded every
 * hour is still re-rendered once a lifetime. That is deliberate. Touching a file on every read
 * turns a read-only path into a write, and the whole point of a bounded lifetime is that stale
 * store configuration eventually drops out (see CanonicalKeyBuilder).
 *
 * Interrupted writes are swept on the same pass. A `.part` file whose renderer died mid-render is
 * never going to be renamed into place, and it is the one thing in this directory that is not
 * reproducible.
 *
 * Every delete is individually guarded. A file removed by a second sweep, a parallel deploy or an
 * operator between the listing and the unlink is not an error worth failing the cron run over.
 */
class CacheSweeper
{
    private readonly WriteInterface $directory;

    public function __construct(
        Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
        $this->directory = $filesystem->getDirectoryWrite(DirectoryList::TMP);
    }

    /**
     * @param int $maxAgeSeconds Files last modified longer ago than this are removed
     * @param int $now UNIX timestamp to measure against
     * @return int How many files were deleted
     */
    public function sweep(int $maxAgeSeconds, int $now): int
    {
        $root = DocumentCache::CACHE_SUBDIRECTORY;

        try {
            if (!$this->directory->isExist($root)) {
                // Nothing has ever been rendered on this node.
                return 0;
            }

            $driver = $this->directory->getDriver();
            $paths = $driver->readDirectoryRecursively($this->directory->getAbsolutePath($root));
        } catch (FileSystemException $e) {
            $this->logger->warning(
                'Scr1be_SignedDocumentDelivery could not list its document cache: ' . $e->getMessage()
            );

            return 0;
        }

        $cutoff = $now - $maxAgeSeconds;
        $deleted = 0;

        foreach ($paths as $absolutePath) {
            if (!$this->isSweepable($absolutePath)) {
                continue;
            }

            try {
                if ($driver->stat($absolutePath)['mtime'] > $cutoff) {
                    continue;
                }

                $driver->deleteFile($absolutePath);
                $deleted++;
            } catch (FileSystemException) {
                // Gone between the listing and here, or not ours to delete. Either way the next
                // run will see the truth.
                continue;
            }
        }

        return $deleted;
    }

    /**
     * Only the two kinds of file this module creates. Anything else under the directory was put
     * there by somebody else and is not a cron job's business.
     */
    private function isSweepable(string $absolutePath): bool
    {
        return str_ends_with($absolutePath, '.' . DocumentCache::EXTENSION)
            || str_ends_with($absolutePath, DocumentCache::IN_FLIGHT_SUFFIX);
    }
}
