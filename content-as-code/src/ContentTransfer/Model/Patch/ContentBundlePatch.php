<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model\Patch;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;
use Scr1be\ContentTransfer\Model\Bundle\BundleStorage;
use Scr1be\ContentTransfer\Model\ImportEngine;
use Scr1be\ContentTransfer\Model\ImportMode;
use Scr1be\ContentTransfer\Model\Outcome;

/**
 * Base class for a data patch that ships content with a module.
 *
 * This is the third entry point into the same engine and the one that matters most in a deploy:
 * `setup:upgrade` runs it, so the content lands in the same release as the code that expects it,
 * with no extra pipeline step to forget.
 *
 * ```php
 * class InstallLandingPages extends ContentBundlePatch
 * {
 *     protected function getBundlePath(): string
 *     {
 *         return 'app/code/Acme/Landing/bundle/pages.json';
 *     }
 * }
 * ```
 *
 * ### Idempotency comes from the mode, not from a flag
 *
 * Magento already remembers which patches ran, in `patch_list`, and will not run this one twice. But
 * "will not run twice" is exactly the guarantee that breaks the day somebody re-imports a database
 * from a build where the patch had not yet run. Defaulting to `ImportMode::Skip` makes a second run
 * a no-op *by itself*, which is a property of the bundle rather than of the bookkeeping around it.
 *
 * It also means the patch never reverts an edit an administrator made after install — which is the
 * behaviour anyone touching production content wants, and the reason `getImportMode()` has to be
 * overridden deliberately to get anything else.
 *
 * ### It lives outside `Setup/Patch/Data`
 *
 * That directory is scanned by Magento to find the patches a module declares, and an abstract class
 * sitting in it is a class the applier will try to instantiate. Subclasses go there; this does not.
 */
abstract class ContentBundlePatch implements DataPatchInterface
{
    public function __construct(
        private readonly BundleStorage $bundleStorage,
        private readonly ImportEngine $importEngine,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Path to the bundle inside the Magento root, e.g. `app/code/Acme/Landing/bundle/pages.json`.
     */
    abstract protected function getBundlePath(): string;

    protected function getImportMode(): ImportMode
    {
        return ImportMode::Skip;
    }

    /**
     * @throws LocalizedException when an entry could not be applied. A patch that logged the
     *         failure and returned would be marked as applied in `patch_list` and never retried,
     *         leaving a half-installed module that looks installed.
     */
    public function apply(): self
    {
        $report = $this->importEngine->apply(
            $this->bundleStorage->read($this->getBundlePath()),
            $this->getImportMode()
        );

        foreach ($report->getRows() as $row) {
            $this->logger->info(
                sprintf(
                    'Content bundle %s: %s/%s %s. %s',
                    $this->getBundlePath(),
                    $row['porter'],
                    $row['identifier'],
                    $row['outcome']->getStatus(),
                    $row['outcome']->getMessage()
                )
            );
        }

        $failures = $report->getFailures();

        if ($failures !== []) {
            throw new LocalizedException(
                __(
                    'Content bundle %1 could not be applied: %2',
                    $this->getBundlePath(),
                    implode('; ', $this->describe($failures))
                )
            );
        }

        return $this;
    }

    /**
     * @param array<int, array{porter: string, identifier: string, outcome: Outcome}> $failures
     * @return string[]
     */
    private function describe(array $failures): array
    {
        return array_map(
            static fn (array $row): string => sprintf(
                '%s/%s (%s)',
                $row['porter'],
                $row['identifier'],
                $row['outcome']->getMessage()
            ),
            $failures
        );
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
