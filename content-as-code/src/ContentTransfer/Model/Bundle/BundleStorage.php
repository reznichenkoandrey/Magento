<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model\Bundle;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Phrase;
use Scr1be\ContentTransfer\Model\Bundle;

/**
 * Bundle files on disk, addressed relative to the Magento root.
 *
 * Root-relative rather than var-relative on purpose: the destination that makes this module worth
 * having is a path inside a module — `app/code/Acme/Content/bundle/content.json` — where the file is
 * committed next to the code that depends on it. A capture that can only write to `var/` is a
 * capture somebody has to move by hand before it becomes content-as-code.
 *
 * Everything goes through `Filesystem`, so a path that escapes the Magento root is rejected by the
 * framework's own driver rather than by a check here that somebody has to remember to write.
 */
class BundleStorage
{
    private ?WriteInterface $root = null;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly JsonCodec $jsonCodec,
        private readonly ZipCodec $zipCodec
    ) {
    }

    /**
     * @return string The root-relative path that was written.
     * @throws LocalizedException
     * @throws FileSystemException
     */
    public function write(Bundle $bundle, string $path): string
    {
        $directory = $this->root();
        $parent = dirname($path);

        if ($parent !== '' && $parent !== '.') {
            $directory->create($parent);
        }

        if ($this->zipCodec->isSupported($path)) {
            // ZipArchive works on real paths, not on the framework's driver abstraction, so this is
            // the one place that has to leave it. The directory write handle above is still what
            // resolves and validates the path.
            $this->zipCodec->write($bundle, $directory->getAbsolutePath($path));

            return $path;
        }

        $directory->writeFile($path, $this->jsonCodec->encode($bundle));

        return $path;
    }

    /**
     * @throws LocalizedException
     * @throws FileSystemException
     */
    public function read(string $path): Bundle
    {
        $directory = $this->root();

        if (!$directory->isExist($path) || !$directory->isFile($path)) {
            throw new LocalizedException(new Phrase('The bundle %1 does not exist.', [$path]));
        }

        if ($this->zipCodec->isSupported($path)) {
            return $this->zipCodec->read($directory->getAbsolutePath($path));
        }

        return $this->jsonCodec->decode($directory->readFile($path));
    }

    public function absolutePath(string $path): string
    {
        return $this->root()->getAbsolutePath($path);
    }

    private function root(): WriteInterface
    {
        return $this->root ??= $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);
    }
}
