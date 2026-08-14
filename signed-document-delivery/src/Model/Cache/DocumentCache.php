<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Cache;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Math\Random;

/**
 * Rendered PDFs on disk, addressed by their canonical key.
 *
 * **Why the filesystem and not the cache framework.** A Magento cache backend is a key/value store
 * that hands you back a string. Serving a 400 KB PDF through it means loading the whole thing into
 * PHP memory to write it to the response — on Redis, over a socket, per download. The response
 * class core already ships (`Magento\Framework\App\Response\File`) streams from a directory in 1 KB
 * chunks and never holds the document in memory; that wants a file, so this is a file.
 *
 * **Why var/tmp.** `DirectoryList`'s default config maps `TMP` to `var/tmp`, which is outside the
 * document root — the files are reachable only through the controller, never by URL guess. It is
 * also the directory an operator already knows is disposable.
 *
 * **Why the writes are atomic.** Two requests for the same document arrive together; both miss;
 * both render; both write. A plain `writeFile()` to the final path lets the second writer's first
 * bytes land while the first writer's reader is halfway through, and the reader gets a truncated
 * PDF with no error anywhere. Writing to a per-writer temporary name and then `rename()`ing is a
 * single atomic directory operation within a filesystem: a reader sees either the old complete file
 * or the new complete file, never a partial one. The losing writer's bytes are simply replaced by
 * identical bytes, which is why no lock is needed — the content is a pure function of the key.
 *
 * **Why the two-level shard.** A shop rendering a few thousand documents a day would otherwise put
 * a few hundred thousand entries in one directory. Two levels of two hex characters from the front
 * of the key gives 65,536 buckets, which keeps directory listings — and the hourly sweep — cheap.
 */
class DocumentCache
{
    /**
     * Relative to var/tmp.
     */
    public const CACHE_SUBDIRECTORY = 'scr1be/signed-documents';

    public const EXTENSION = 'pdf';

    /**
     * Suffix on in-flight writes. The sweep knows about it so an interrupted render leaves rubbish
     * for at most one hour rather than forever.
     */
    public const IN_FLIGHT_SUFFIX = '.part';

    private const SHARD_LEVELS = 2;
    private const SHARD_WIDTH = 2;

    private const IN_FLIGHT_TOKEN_LENGTH = 12;

    private readonly WriteInterface $directory;

    public function __construct(
        Filesystem $filesystem,
        private readonly Random $random
    ) {
        $this->directory = $filesystem->getDirectoryWrite(DirectoryList::TMP);
    }

    /**
     * The directory code `Magento\Framework\App\Response\Http\FileFactory` has to be given so it
     * resolves the same relative paths this class hands out.
     */
    public function directoryCode(): string
    {
        return DirectoryList::TMP;
    }

    /**
     * `scr1be/signed-documents/ab/cd/abcd…ef.pdf`
     */
    public function relativePath(string $key): string
    {
        $shards = [];
        for ($level = 0; $level < self::SHARD_LEVELS; $level++) {
            $shards[] = substr($key, $level * self::SHARD_WIDTH, self::SHARD_WIDTH);
        }

        return self::CACHE_SUBDIRECTORY . '/' . implode('/', $shards) . '/' . $key . '.' . self::EXTENSION;
    }

    /**
     * @throws FileSystemException
     */
    public function has(string $key): bool
    {
        return $this->directory->isFile($this->relativePath($key));
    }

    /**
     * Write the bytes so that no reader can ever see half of them.
     *
     * @throws FileSystemException
     */
    public function write(string $key, string $contents): void
    {
        $finalPath = $this->relativePath($key);
        $inFlightPath = $finalPath . '.' . $this->random->getRandomString(self::IN_FLIGHT_TOKEN_LENGTH)
            . self::IN_FLIGHT_SUFFIX;

        $this->directory->create(dirname($finalPath));
        $this->directory->writeFile($inFlightPath, $contents);
        $this->directory->renameFile($inFlightPath, $finalPath);
    }
}
