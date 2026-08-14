<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Cron;

use Scr1be\CuratedCategories\Model\CurationLog;
use Scr1be\CuratedCategories\Model\SourcePool;
use Scr1be\CuratedCategories\Model\SourceRunner;

/**
 * One cron class, three schedules.
 *
 * The adapters differ in how often they are worth running, not in what running them means, so the
 * schedule is the configuration and the job is a virtual type with a list of source codes —
 * bestsellers daily, new arrivals and coming soon hourly. Three near-identical cron classes would
 * have been three places to fix the next time the failure policy changes.
 *
 * That policy: a source that throws is logged and the loop continues. Cron groups run their jobs in
 * sequence in one process, so an uncaught exception here would take the rest of the group with it —
 * and "the coming-soon feed is stale" is a much smaller problem than "nothing in the default cron
 * group ran last night".
 */
class ReconcileSources
{
    /**
     * @param string[] $sourceCodes
     */
    public function __construct(
        private readonly SourcePool $sourcePool,
        private readonly SourceRunner $runner,
        private readonly CurationLog $log,
        private readonly array $sourceCodes = []
    ) {
    }

    public function execute(): void
    {
        foreach ($this->sourceCodes as $code) {
            if (!$this->sourcePool->has($code)) {
                $this->log->logSkipped((string) $code, 'source is not registered');

                continue;
            }

            $source = $this->sourcePool->get($code);

            if (!$source->isEnabled()) {
                continue;
            }

            try {
                $this->runner->run($source);
            } catch (\Throwable $exception) {
                $this->log->logFailure($code, $exception);
            }
        }
    }
}
