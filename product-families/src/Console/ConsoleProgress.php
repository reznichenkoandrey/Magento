<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Console;

use Scr1be\ProductFamilies\Api\ReconcileProgressInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The console's half of {@see ReconcileProgressInterface}.
 *
 * It is constructed by the command rather than by the object manager because it needs the output
 * stream of the invocation it belongs to. That is also why the reconcile takes the interface as an
 * argument instead of injecting an implementation: nothing outside the CLI has a progress bar, and
 * the cron passes null.
 *
 * The bar is created on `start()` and not before, because the total — the number of families the
 * scan found — does not exist until the scan and the grouping have both finished.
 */
class ConsoleProgress implements ReconcileProgressInterface
{
    private ?ProgressBar $bar = null;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly string $label
    ) {
    }

    public function start(int $total): void
    {
        $this->bar = new ProgressBar($this->output, $total);
        $this->bar->setFormat(
            sprintf('  %s  %%current%%/%%max%% families [%%bar%%] %%percent:3s%%%% %%elapsed:6s%%', $this->label)
        );
        $this->bar->start();
    }

    public function advance(int $step = 1): void
    {
        $this->bar?->advance($step);
    }

    public function finish(): void
    {
        if ($this->bar === null) {
            return;
        }

        $this->bar->finish();
        $this->output->writeln('');
        $this->bar = null;
    }
}
