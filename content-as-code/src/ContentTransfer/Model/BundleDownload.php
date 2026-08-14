<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Math\Random;
use Scr1be\ContentTransfer\Model\Bundle\BundleStorage;

/**
 * Captures a selection and hands it to the browser as a download.
 *
 * The bundle is written to a uniquely-named file under `var/` first, even for the JSON format that
 * could have been streamed from memory. Two reasons, and the first is the real one:
 *
 * - a zip has to exist as a file anyway, because `ZipArchive` writes to a path, so one code path for
 *   both formats is one code path to get right;
 * - the name is unique per request, so two administrators exporting at the same moment do not write
 *   over each other — which a fixed temp name in a shared `var/` directory absolutely allows.
 *
 * `Magento\Framework\App\Response\File::sendResponse()` deletes the file after streaming it when
 * `remove` is set, so nothing accumulates.
 */
class BundleDownload
{
    private const TEMP_DIRECTORY = 'scr1be_content_transfer';

    private const DOWNLOAD_NAME = 'content-bundle';

    public function __construct(
        private readonly ExportEngine $exportEngine,
        private readonly BundleStorage $bundleStorage,
        private readonly FileFactory $fileFactory,
        private readonly Random $random
    ) {
    }

    /**
     * @throws \Exception when the temporary file cannot be written or read back.
     */
    public function create(Selection $selection, bool $asZip): ResponseInterface
    {
        $extension = $asZip ? 'zip' : 'json';

        $temporaryPath = sprintf(
            'var/%s/%s.%s',
            self::TEMP_DIRECTORY,
            $this->random->getUniqueHash('bundle-'),
            $extension
        );

        $this->bundleStorage->write($this->exportEngine->capture($selection), $temporaryPath);

        return $this->fileFactory->create(
            self::DOWNLOAD_NAME . '.' . $extension,
            [
                'type' => 'filename',
                'value' => $temporaryPath,
                'rm' => true,
            ],
            DirectoryList::ROOT,
            $asZip ? 'application/zip' : 'application/json'
        );
    }
}
