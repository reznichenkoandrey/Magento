<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Scr1be\CuratedCategories\Api\CurationSourceInterface;
use Scr1be\CuratedCategories\Api\Data\CurationResultInterface;
use Scr1be\CuratedCategories\Model\CurationLog;
use Scr1be\CuratedCategories\Model\SourcePool;
use Scr1be\CuratedCategories\Model\SourceRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento scr1be:curated:reconcile [source] [--dry-run] [--verbose-ids]`
 *
 * The dry run is not a convenience, it is the reason the command exists. A merchandising rule is
 * written in an admin form and its effect is invisible until it has already replaced the contents of
 * a category — so every adapter has to be answerable to "show me what you would do" before anyone
 * schedules it. `--dry-run` takes the identical path a cron run takes, up to and not including the
 * two write statements, so what the table prints is what the run would have done rather than a
 * second implementation's opinion of it.
 *
 * Exit codes are for the deployment pipeline that will eventually run this: zero when every selected
 * source completed, one when any of them threw. A *refused* run is not a failure — the guards did
 * their job — so it prints its reason and still exits zero.
 */
class ReconcileCommand extends Command
{
    private const ARGUMENT_SOURCE = 'source';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_IDS = 'verbose-ids';

    /**
     * How many ids to print per bucket before truncating. A 1,000-product feed's first run has 1,000
     * ids in the added column, and a terminal is not the place to read them — that is what the log
     * is for.
     */
    private const MAX_PRINTED_IDS = 20;

    public function __construct(
        private readonly SourcePool $sourcePool,
        private readonly SourceRunner $runner,
        private readonly CurationLog $log,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('scr1be:curated:reconcile')
            ->setDescription('Reconcile curated category membership from its configured sources.')
            ->addArgument(
                self::ARGUMENT_SOURCE,
                InputArgument::OPTIONAL,
                'Source code to run. Omit to run every enabled source.'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Compute the plan and print it without writing anything.'
            )
            ->addOption(
                self::OPTION_IDS,
                null,
                InputOption::VALUE_NONE,
                'Print the affected product ids instead of just the counts.'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Product collections resolve attribute values through the design and store scope, which the
        // CLI has not set. Crontab is the honest area for a job that is normally run by cron; the
        // guard is there because `setAreaCode()` throws if something in the DI graph got there first.
        try {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        } catch (LocalizedException) {
            // Already set — nothing to do.
        }

        $dryRun = (bool) $input->getOption(self::OPTION_DRY_RUN);

        try {
            $sources = $this->resolveSources((string) $input->getArgument(self::ARGUMENT_SOURCE));
        } catch (LocalizedException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($sources === []) {
            $output->writeln('<comment>No enabled curated category sources.</comment>');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $output->writeln('<comment>Dry run — nothing will be written.</comment>');
        }

        return $this->runAll($sources, $dryRun, (bool) $input->getOption(self::OPTION_IDS), $output);
    }

    /**
     * @param CurationSourceInterface[] $sources
     */
    private function runAll(array $sources, bool $dryRun, bool $printIds, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['Source', 'Category', 'Added', 'Removed', 'Unchanged', 'Kept by floor', 'Note']);

        $exitCode = Command::SUCCESS;

        foreach ($sources as $code => $source) {
            try {
                $table->addRow($this->describe($this->runner->run($source, $dryRun), $printIds));
            } catch (\Throwable $exception) {
                $this->log->logFailure((string) $code, $exception);
                $table->addRow([(string) $code, '-', '-', '-', '-', '-', '<error>' . $exception->getMessage() . '</error>']);
                $exitCode = Command::FAILURE;
            }
        }

        $table->render();

        return $exitCode;
    }

    /**
     * @return array<int, string>
     */
    private function describe(CurationResultInterface $result, bool $printIds): array
    {
        $note = $result->isRefused() ? '<comment>' . $result->getRefusalReason() . '</comment>' : '';

        return [
            $result->getSourceCode(),
            $result->getCategoryId() > 0 ? (string) $result->getCategoryId() : '-',
            $this->format($result->getAdded(), $printIds),
            $this->format($result->getRemoved(), $printIds),
            $this->format($result->getUnchanged(), $printIds),
            $this->format($result->getRetainedByFloor(), $printIds),
            $note,
        ];
    }

    /**
     * @param int[] $ids
     */
    private function format(array $ids, bool $printIds): string
    {
        if (!$printIds) {
            return (string) count($ids);
        }

        if ($ids === []) {
            return '0';
        }

        $shown = array_slice($ids, 0, self::MAX_PRINTED_IDS);
        $suffix = count($ids) > self::MAX_PRINTED_IDS
            ? sprintf(' … (+%d)', count($ids) - self::MAX_PRINTED_IDS)
            : '';

        return sprintf('%d: %s%s', count($ids), implode(', ', $shown), $suffix);
    }

    /**
     * @return CurationSourceInterface[] Keyed by code.
     * @throws LocalizedException When a named source does not exist.
     */
    private function resolveSources(string $requested): array
    {
        if ($requested === '') {
            return $this->sourcePool->getEnabled();
        }

        // A named source runs even when it is switched off. Someone who typed the code meant that
        // one, and refusing on the grounds of a setting they can see would only cost them a round
        // trip through the admin.
        return [$requested => $this->sourcePool->get($requested)];
    }
}
