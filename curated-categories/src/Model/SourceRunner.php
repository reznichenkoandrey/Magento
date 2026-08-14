<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model;

use Scr1be\CuratedCategories\Api\CurationEngineInterface;
use Scr1be\CuratedCategories\Api\CurationSourceInterface;
use Scr1be\CuratedCategories\Api\Data\CurationResultInterface;

/**
 * The four lines that turn a source into a reconcile, in one place so that cron and the CLI cannot
 * drift apart.
 *
 * The runner deliberately does *not* catch. A source that throws is a real fault — a missing
 * attribute, an unavailable database — and the two callers want opposite things from it: cron has to
 * log it and carry on so the rest of the group still runs, the CLI has to report it and exit
 * non-zero so a deployment notices. Swallowing it here would take that choice away from both.
 */
class SourceRunner
{
    private const REFUSAL_NOT_CONFIGURED = 'source has no usable target category';

    public function __construct(
        private readonly CurationEngineInterface $engine,
        private readonly CurationLog $log
    ) {
    }

    public function run(CurationSourceInterface $source, bool $dryRun = false): CurationResultInterface
    {
        $target = $source->getTarget();

        if ($target === null) {
            // Modelled as a refused result rather than a null return so that every path out of a run
            // — refused, empty, applied — is the same type, and the CLI table has one shape.
            $result = CurationResult::refused(
                new CurationTarget(0, Config::MIN_FLOOR, $source->getCode()),
                self::REFUSAL_NOT_CONFIGURED
            );
        } else {
            $result = $this->engine->reconcileAll($target, $source->getProductIds(), $dryRun);
        }

        $this->log->logResult($result);

        return $result;
    }
}
