<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Console\Command;

use Magento\Framework\Console\Cli;
use Scr1be\ContentTransfer\Model\Bundle\BundleStorage;
use Scr1be\ContentTransfer\Model\ImportEngine;
use Scr1be\ContentTransfer\Model\ImportMode;
use Scr1be\ContentTransfer\Model\ImportReport;
use Scr1be\ContentTransfer\Model\Outcome;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * `bin/magento content:apply` — replay a bundle onto this install.
 *
 * Exits non-zero when any entry failed, so a deploy step that runs this stops the pipeline. Entries
 * that were skipped because they already exist are not failures: on the second and every subsequent
 * deploy, "everything skipped" is the expected, successful outcome.
 */
class ApplyCommand extends Command
{
    private const ARGUMENT_BUNDLE = 'bundle';
    private const OPTION_REPLACE = 'replace';
    private const OPTION_DRY_RUN = 'dry-run';

    public function __construct(
        private readonly BundleStorage $bundleStorage,
        private readonly ImportEngine $importEngine,
        private readonly AreaState $areaState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('content:apply');
        $this->setDescription('Apply a captured content bundle to this installation.');

        $this->addArgument(
            self::ARGUMENT_BUNDLE,
            InputArgument::REQUIRED,
            'Bundle path, relative to the Magento root (.json or .zip).'
        );

        $this->addOption(
            self::OPTION_REPLACE,
            'r',
            InputOption::VALUE_NONE,
            'Overwrite entities that already exist. Without it they are left exactly as they are.'
        );

        $this->addOption(
            self::OPTION_DRY_RUN,
            null,
            InputOption::VALUE_NONE,
            'Report what would happen and write nothing.'
        );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption(self::OPTION_DRY_RUN);
        $mode = $input->getOption(self::OPTION_REPLACE) ? ImportMode::Replace : ImportMode::Skip;

        try {
            $this->areaState->ensureAdminArea();

            $bundle = $this->bundleStorage->read((string)$input->getArgument(self::ARGUMENT_BUNDLE));

            $report = $dryRun
                ? $this->importEngine->preview($bundle, $mode)
                : $this->importEngine->apply($bundle, $mode);
        } catch (Throwable $exception) {
            // Reaching here means the bundle itself could not be read — a broken file, a format from
            // the future. Nothing was applied, so this is the one failure that is all-or-nothing.
            $style->error($exception->getMessage());

            return Cli::RETURN_FAILURE;
        }

        $this->render($style, $report, $dryRun);

        return $report->hasFailures() ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
    }

    private function render(SymfonyStyle $style, ImportReport $report, bool $dryRun): void
    {
        $rows = [];

        foreach ($report->getRows() as $row) {
            $rows[] = [
                $row['porter'],
                $row['identifier'],
                $row['outcome']->getStatus(),
                $row['outcome']->getMessage(),
            ];
        }

        $style->table(['Porter', 'Entry', $dryRun ? 'Would be' : 'Result', 'Detail'], $rows);

        $totals = $report->getTotals();
        $summary = sprintf(
            '%d created, %d replaced, %d skipped, %d failed',
            $totals[Outcome::STATUS_CREATED],
            $totals[Outcome::STATUS_REPLACED],
            $totals[Outcome::STATUS_SKIPPED],
            $totals[Outcome::STATUS_FAILED]
        );

        if ($dryRun) {
            $style->note('Dry run — nothing was written. ' . $summary);

            return;
        }

        if ($report->hasFailures()) {
            $style->error($summary);

            return;
        }

        $style->success($summary);
    }
}
