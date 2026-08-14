<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Robots;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;

/**
 * Puts one website's robots.txt on disk.
 *
 * Two decisions worth defending.
 *
 * **Why a file at all**, when Magento_Robots already answers `/robots.txt` from a controller:
 * because on a tuned storefront that controller is often never reached. `robots.txt` is fetched by
 * crawlers before anything else, from edges and mirrors, and the usual nginx configuration serves
 * a matching static file directly. A physical file is also what survives PHP being down, which is
 * the one moment you least want a crawler to see a 503 and cache it.
 *
 * **Why the media directory** rather than `pub/`: the media directory is the only one Magento
 * guarantees writable at runtime — a read-only deployment (`pub/` shipped from an image, code
 * immutable) is normal, and a module that writes into `pub/` works on a developer machine and
 * fails on the first real deploy. The webserver rule that maps `/robots.txt` to the right file is
 * documented in the README; without it nothing breaks, because the core controller still answers.
 */
class FileWriter
{
    /**
     * Media-relative directory the published files live in.
     */
    public const TARGET_DIRECTORY = 'scr1be/robots';

    /**
     * Website codes are used verbatim as file names, so they are checked against the character set
     * a Magento website code is allowed to use rather than trusted. A code carrying `../` would
     * otherwise turn a config save into an arbitrary write.
     */
    private const SAFE_CODE_PATTERN = '/^[a-zA-Z0-9_]+$/';

    private WriteInterface $mediaDirectory;

    public function __construct(Filesystem $filesystem)
    {
        $this->mediaDirectory = $filesystem->getDirectoryWrite(DirectoryList::MEDIA);
    }

    /**
     * Writes the file and returns its media-relative path.
     *
     * @throws LocalizedException
     * @throws FileSystemException
     */
    public function publish(string $websiteCode, string $content): string
    {
        $path = $this->getRelativePath($websiteCode);

        // Written to a sibling and renamed into place: a crawler that requests the file halfway
        // through a config save gets the old bytes or the new ones, never a half-written mixture.
        $temporaryPath = $path . '.tmp';

        $this->mediaDirectory->writeFile($temporaryPath, $content);
        $this->mediaDirectory->renameFile($temporaryPath, $path);

        return $path;
    }

    /**
     * Removing the file rather than writing an empty one, so that turning the feature off falls
     * back to whatever the webserver did before instead of serving zero bytes.
     *
     * @throws LocalizedException
     * @throws FileSystemException
     */
    public function remove(string $websiteCode): void
    {
        $path = $this->getRelativePath($websiteCode);

        if ($this->mediaDirectory->isExist($path)) {
            $this->mediaDirectory->delete($path);
        }
    }

    /**
     * @throws LocalizedException
     */
    public function getRelativePath(string $websiteCode): string
    {
        if (preg_match(self::SAFE_CODE_PATTERN, $websiteCode) !== 1) {
            throw new LocalizedException(
                __('"%1" is not a usable website code for a robots.txt file name.', $websiteCode)
            );
        }

        return self::TARGET_DIRECTORY . '/' . $websiteCode . '.txt';
    }
}
