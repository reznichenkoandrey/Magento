<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Psr\Log\LoggerInterface;

/**
 * The module's only door to pub/media.
 *
 * Two things are centralised here rather than repeated at each call site. First, the directory
 * interfaces throw FileSystemException for the entirely ordinary case of "not there yet", and a
 * cache layer asks that question more often than it asks anything else — so the surface is
 * re-expressed as null/false and the exception never leaves this class. Second, going through
 * Filesystem rather than PHP's own file functions is what keeps the module working under remote
 * storage: Magento_RemoteStorage's etc/di.xml preferences Magento\Framework\Filesystem to
 * customRemoteFilesystem, a Magento\RemoteStorage\Filesystem whose directoryCodes list contains
 * DirectoryList::MEDIA, and whose getDirectoryRead() then returns a directory bound to the remote
 * driver. getAbsolutePath() under that driver is not something the local process can fopen, which
 * rules out getimagesize(), file_get_contents() and every other path-taking builtin.
 */
class MediaStorage
{
    private ?WriteInterface $writeDirectory = null;
    private ?ReadInterface $readDirectory = null;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{mtime: int, size: int}|null
     */
    public function stat(string $path): ?array
    {
        try {
            $stat = $this->readDir()->stat($path);
        } catch (FileSystemException) {
            // Absent, unreadable, or a directory — all three mean "no usable file", and none of
            // them is something the caller could act on differently.
            return null;
        }

        return [
            'mtime' => (int) ($stat['mtime'] ?? 0),
            'size' => (int) ($stat['size'] ?? 0),
        ];
    }

    public function mtime(string $path): ?int
    {
        return $this->stat($path)['mtime'] ?? null;
    }

    /**
     * Reads at most $length bytes from the head of a file without pulling the rest. Under remote
     * storage this is the difference between a range request and fetching a whole object.
     */
    public function readHead(string $path, int $length): ?string
    {
        try {
            $file = $this->readDir()->openFile($path);
        } catch (FileSystemException $e) {
            $this->logger->debug('Scr1be_HyvaMedia: cannot open ' . $path . ': ' . $e->getMessage());

            return null;
        }

        $bytes = null;
        try {
            $bytes = $file->read($length);
        } catch (FileSystemException $e) {
            $this->logger->debug('Scr1be_HyvaMedia: cannot read ' . $path . ': ' . $e->getMessage());
        } finally {
            try {
                $file->close();
            } catch (FileSystemException) {
                // A handle that cannot be closed has nothing left to tell us, and letting it
                // propagate would discard bytes we already read successfully.
            }
        }

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    public function readAll(string $path): ?string
    {
        try {
            $contents = $this->readDir()->readFile($path);
        } catch (FileSystemException $e) {
            $this->logger->warning('Scr1be_HyvaMedia: cannot read ' . $path . ': ' . $e->getMessage());

            return null;
        }

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * Parent directories are not created here: Directory\Write::openFile(), which writeFile()
     * delegates to, calls create() on the dirname before opening.
     */
    public function write(string $path, string $contents): bool
    {
        try {
            $this->writeDir()->writeFile($path, $contents);
        } catch (FileSystemException $e) {
            // A read-only or full media volume must degrade to "no derivative", never to a 500 on a
            // content page. The warning is the signal; the page keeps its original image.
            $this->logger->warning('Scr1be_HyvaMedia: cannot write ' . $path . ': ' . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Zero-byte marker file. touch() creates its parent directory the same way openFile() does.
     */
    public function touch(string $path): bool
    {
        try {
            $this->writeDir()->touch($path);
        } catch (FileSystemException $e) {
            $this->logger->warning('Scr1be_HyvaMedia: cannot touch ' . $path . ': ' . $e->getMessage());

            return false;
        }

        return true;
    }

    private function readDir(): ReadInterface
    {
        if ($this->readDirectory === null) {
            $this->readDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        }

        return $this->readDirectory;
    }

    private function writeDir(): WriteInterface
    {
        if ($this->writeDirectory === null) {
            $this->writeDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        }

        return $this->writeDirectory;
    }
}
