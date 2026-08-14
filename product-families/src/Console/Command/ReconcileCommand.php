<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Scr1be\ProductFamilies\Api\Data\ReconcileResultInterface;
use Scr1be\ProductFamilies\Api\FamilyReconcilerInterface;
use Scr1be\ProductFamilies\Console\ConsoleProgress;
use Scr1be\ProductFamilies\Model\FamilyDefinitionPool;
use Scr1be\ProductFamilies\Model\FamilyLog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento scr1be:families:reconcile [family] [--dry-run]`
 *
 * A family definition is two attribute codes in an admin form, and what those two codes do to a
 * 40,000-product catalogue is not something anyone can picture. `--dry-run` takes the identical path
 * a cron run takes, up to and not including the writer, so the table it prints is the plan itself
 * rather than a second implementation's guess at it.
 *
 * Exit codes are for the pipeline that will eventually run this: zero when every selected family
 * completed, one when any of them threw. A *refused* family — switched off, or missing its group
 * attribute — is not a failure: it prints its reason and the command still exits zero.
 */
class ReconcileCommand extends Command
{
    private const ARGUMENT_FAMILY = 'family';
    private const OPTION_DRY_RUN = 'dry-run';

    public function __construct(
        private readonly FamilyReconcilerInterface $reconciler,
        private readonly FamilyDefinitionPool $definitionPool,
        private readonly FamilyLog $log,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('scr1be:families:reconcile')
            ->setDescription('Rebuild automatic product family links from the catalogue.')
            ->addArgument(
                self::ARGUMENT_FAMILY,
                InputArgument::OPTIONAL,
                'Family code to run (other_colors, other_sizes, similar). Omit to run all of them.'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Compute the plan and print it without writing anything.'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Product collections and attribute sources resolve through the design and store scope,
        // which the CLI has not set. Crontab is the honest area for a job that is normally run by
        // cron; the guard is there because `setAreaCode()` throws if something in the DI graph got
        // there first.
        try {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        } catch (LocalizedException) {
            // Already set — nothing to do.
        }

        $requested = (string)($input->getArgument(self::ARGUMENT_FAMILY) ?? '');
        if ($requested !== '' && !$this->definitionPool->has($requested)) {
            $output->writeln(sprintf(
                '<error>Unknown family "%s". Known families: %s.</error>',
                $requested,
                implode(', ', $this->definitionPool->getFamilyCodes())
            ));

            return Command::FAILURE;
        }

        $familyCodes = $requested !== '' ? [$requested] : $this->definitionPool->getFamilyCodes();
        $dryRun = (bool)$input->getOption(self::OPTION_DRY_RUN);

        if ($dryRun) {
            $output->writeln('<comment>Dry run — nothing will be written.</comment>');
        }

        $results = [];
        $failed = false;

        foreach ($familyCodes as $familyCode) {
            try {
                $result = $this->reconciler->reconcile(
                    $familyCode,
                    $dryRun,
                    new ConsoleProgress($output, $familyCode)
                );
                $this->log->logResult($result);
                $results[] = $result;
            } catch (\Throwable $error) {
                // One misconfigured family must not stop the ones after it: the command is run
                // unattended often enough that "the first failure hid two successes" is a real cost.
                $this->log->logFailure($familyCode, $error);
                $output->writeln(sprintf('<error>%s: %s</error>', $familyCode, $error->getMessage()));
                $failed = true;
            }
        }

        $this->renderTable($output, $results);

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param ReconcileResultInterface[] $results
     */
    private function renderTable(OutputInterface $output, array $results): void
    {
        if ($results === []) {
            return;
        }

        $rows = [];
        foreach ($results as $result) {
            if ($result->isRefused()) {
                $rows[] = [
                    $result->getFamilyCode(),
                    sprintf('<comment>refused — %s</comment>', (string)$result->getRefusalReason()),
                    '', '', '', '', '', '',
                ];
                continue;
            }

            $rows[] = [
                $result->getFamilyCode(),
                $result->isDryRun() ? 'planned' : 'written',
                (string)$result->getFamilyCount(),
                (string)$result->getMemberCount(),
                (string)$result->getInsertedCount(),
                (string)$result->getUpdatedCount(),
                (string)$result->getDeletedCount(),
                (string)count($result->getAffectedProductIds()),
            ];
        }

        (new Table($output))
            ->setHeaders(['Family', 'Outcome', 'Families', 'Members', 'Added', 'Moved', 'Removed', 'Invalidated'])
            ->setRows($rows)
            ->render();
    }
}
