<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Replays a bundle onto this install.
 *
 * **Per-entry isolation, not a transaction.** A 200-entry bundle wrapped in one transaction fails on
 * entry 137 and leaves the deploy with nothing — including the 136 entries that were fine and the 63
 * nobody got to. Because the format is idempotent (identifier matching, skip-if-exists), the useful
 * behaviour is the opposite: apply everything that can be applied, report precisely what could not,
 * exit non-zero, and let the operator fix one entry and re-run. The second run skips the 199 that
 * landed. That property is worth more than atomicity here, and it is the reason `apply()` is allowed
 * to throw — the porter reports the failure by failing, and the engine turns it into a row.
 *
 * **Caches are invalidated here, explicitly.** `Magento\Widget\Model\Widget\Instance` invalidates
 * `block_html` / `layout` / `full_page` on save through its `relatedCacheTypes` constructor
 * argument, but that argument is only ever supplied by `Magento_Widget/etc/adminhtml/di.xml` and
 * `Magento_PageCache/etc/adminhtml/di.xml`; its declared default is `[]` and `_invalidateCache()`
 * is guarded by `count($this->_relatedCacheTypes)`. `Magento\Framework\Console\Cli` sets no area
 * code, so a widget instance written by `content:apply` gets the global-scope object and invalidates
 * nothing. Doing it here means the same thing happens whichever entry point ran the import.
 */
class ImportEngine
{
    /**
     * Core cache type codes, spelled the way core's own di.xml spells them: plain strings, so that
     * invalidating the page cache does not turn Magento_PageCache into a hard dependency of a module
     * that otherwise does not care whether it is installed.
     */
    private const INVALIDATED_CACHE_TYPES = ['block_html', 'layout', 'full_page'];

    public function __construct(
        private readonly PorterPool $porterPool,
        private readonly TypeListInterface $cacheTypeList,
        private readonly LoggerInterface $logger
    ) {
    }

    public function apply(Bundle $bundle, ImportMode $mode): ImportReport
    {
        $report = new ImportReport();

        $this->recordUnportableEntries($bundle, $report);

        foreach ($this->porterPool->getSorted() as $porter) {
            foreach ($bundle->getEntriesFor($porter->getCode()) as $entry) {
                try {
                    $outcome = $porter->apply($entry, $mode);
                } catch (Throwable $exception) {
                    // The message goes in the report for the operator and the trace goes in the log
                    // for whoever has to work out why: a console table is not the place for a stack.
                    $this->logger->error(
                        sprintf(
                            'Content transfer failed to apply %s/%s: %s',
                            $entry->getPorterCode(),
                            $entry->getIdentifier(),
                            $exception->getMessage()
                        ),
                        ['exception' => $exception]
                    );

                    $outcome = Outcome::failed($exception->getMessage());
                }

                $report->record($entry, $outcome);
            }
        }

        if ($report->hasWrites()) {
            $this->cacheTypeList->invalidate(self::INVALIDATED_CACHE_TYPES);
        }

        return $report;
    }

    /**
     * A bundle may name a porter this install does not have — it was captured somewhere with an
     * extra module. Those entries are failures, not silent omissions: an import that reports success
     * while dropping every coupon ticket on the floor is the failure mode this whole design is meant
     * to make impossible.
     */
    private function recordUnportableEntries(Bundle $bundle, ImportReport $report): void
    {
        foreach ($bundle->getEntries() as $entry) {
            if ($this->porterPool->has($entry->getPorterCode())) {
                continue;
            }

            $report->record(
                $entry,
                Outcome::failed(
                    (string)__(
                        'No porter is registered for "%1"; the module that captured it is not '
                        . 'installed here.',
                        $entry->getPorterCode()
                    )
                )
            );
        }
    }

    /**
     * Read the bundle and report what `apply()` would do, without calling it.
     *
     * The prediction comes from `PorterInterface::exists()`, never from `apply()` with a flag: a
     * write path that takes a "don't write" argument is one careless `if` away from writing anyway,
     * and the run where that gets noticed is the one on production.
     *
     * `hasWrites()` on the returned report is therefore true for a dry run that would change
     * something — which is exactly what a caller wants to branch on, and why this method does not
     * invalidate any caches.
     */
    public function preview(Bundle $bundle, ImportMode $mode): ImportReport
    {
        $report = new ImportReport();

        $this->recordUnportableEntries($bundle, $report);

        foreach ($this->porterPool->getSorted() as $porter) {
            foreach ($bundle->getEntriesFor($porter->getCode()) as $entry) {
                try {
                    $outcome = $this->predict($porter->exists($entry), $mode);
                } catch (Throwable $exception) {
                    $outcome = Outcome::failed($exception->getMessage());
                }

                $report->record($entry, $outcome);
            }
        }

        return $report;
    }

    private function predict(bool $exists, ImportMode $mode): Outcome
    {
        if (!$exists) {
            return Outcome::created((string)__('Would be created.'));
        }

        return $mode->replacesExisting()
            ? Outcome::replaced((string)__('Exists here and would be overwritten.'))
            : Outcome::skipped((string)__('Exists here; run with --replace to overwrite it.'));
    }
}
