<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Console\Command;

use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Scr1be\ContentTransfer\Model\Bundle\BundleStorage;
use Scr1be\ContentTransfer\Model\ExportEngine;
use Scr1be\ContentTransfer\Model\PorterPool;
use Scr1be\ContentTransfer\Model\Selection;
use Scr1be\ContentTransfer\Model\StoreScope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * `bin/magento content:capture` — write the selected content to a bundle file.
 *
 * Every transform is printed, not summarised. The whole value of a rewritten reference is that
 * somebody can check it: "block_id 12 → footer-links" is reviewable, "17 references rewritten" is
 * not. Warnings are printed last, after the summary, because they are the part that needs acting on
 * and the part most likely to scroll off the top of a terminal.
 */
class CaptureCommand extends Command
{
    private const ARGUMENT_OUTPUT = 'output';
    private const OPTION_STORE = 'store';
    private const OPTION_PORTER = 'porter';
    private const OPTION_IDENTIFIER = 'identifier';

    public function __construct(
        private readonly ExportEngine $exportEngine,
        private readonly BundleStorage $bundleStorage,
        private readonly PorterPool $porterPool,
        private readonly StoreScope $storeScope,
        private readonly AreaState $areaState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('content:capture');
        $this->setDescription('Capture CMS content into a version-controllable bundle file.');

        $this->addArgument(
            self::ARGUMENT_OUTPUT,
            InputArgument::REQUIRED,
            'Destination path, relative to the Magento root. A ".zip" extension writes an exploded '
            . 'archive; anything else writes a single JSON document.'
        );

        $this->addOption(
            self::OPTION_STORE,
            's',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Store view code to capture. Repeatable. Omit for every store view.'
        );

        $this->addOption(
            self::OPTION_PORTER,
            'p',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Content type to capture (' . implode(', ', array_keys($this->porterPool->getAll()))
            . '). Repeatable. Omit for all of them.'
        );

        $this->addOption(
            self::OPTION_IDENTIFIER,
            'i',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Capture one entry only, as "porter:key" (e.g. cms_page:about-us). Repeatable. Implies '
            . '--porter for the porter named.'
        );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);

        try {
            $this->areaState->ensureAdminArea();

            $bundle = $this->exportEngine->capture($this->selection($input));
            $path = $this->bundleStorage->write($bundle, (string)$input->getArgument(self::ARGUMENT_OUTPUT));
        } catch (Throwable $exception) {
            $style->error($exception->getMessage());

            return Cli::RETURN_FAILURE;
        }

        $warnings = [];

        foreach ($bundle->getEntries() as $entry) {
            foreach ($entry->getTransforms() as $transform) {
                $style->writeln('  <info>rewrote</info> ' . $transform);
            }

            foreach ($entry->getWarnings() as $warning) {
                $warnings[] = $warning;
            }
        }

        $rows = [];

        foreach ($bundle->getManifest()->getCounts() as $code => $count) {
            $rows[] = [$this->porterPool->get($code)->getLabel(), $code, $count];
        }

        $style->table(['Content', 'Porter', 'Captured'], $rows);
        $style->success(sprintf('%d entries written to %s', $bundle->getManifest()->getTotalCount(), $path));

        if ($warnings !== []) {
            // Printed after the summary and not as an error: the bundle is written and usable, and
            // these are the references a human has to decide about.
            $style->warning(sprintf('%d reference(s) could not be made portable:', count($warnings)));
            $style->listing($warnings);
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @throws LocalizedException
     */
    private function selection(InputInterface $input): Selection
    {
        $storeCodes = (array)$input->getOption(self::OPTION_STORE);
        $identifiers = [];

        foreach ((array)$input->getOption(self::OPTION_PORTER) as $code) {
            $this->porterPool->get((string)$code);
            $identifiers[(string)$code] = [];
        }

        foreach ((array)$input->getOption(self::OPTION_IDENTIFIER) as $pair) {
            [$code, $key] = $this->splitIdentifier((string)$pair);
            $this->porterPool->get($code);
            $identifiers[$code][] = $key;
        }

        return new Selection(
            $storeCodes === [] ? [] : $this->storeScope->toIds($storeCodes),
            $identifiers
        );
    }

    /**
     * @return array{0: string, 1: string}
     * @throws LocalizedException
     */
    private function splitIdentifier(string $pair): array
    {
        // Split on the first colon only: a bundle key may contain one, a porter code may not.
        $position = strpos($pair, ':');

        if ($position === false || $position === 0 || $position === strlen($pair) - 1) {
            throw new LocalizedException(
                __('--identifier expects "porter:key", got "%1".', $pair)
            );
        }

        return [substr($pair, 0, $position), substr($pair, $position + 1)];
    }
}
