<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model;

use Psr\Log\LoggerInterface;
use Scr1be\CuratedCategories\Api\Data\CurationResultInterface;

/**
 * One line per run, in `var/log/curated_categories.log`.
 *
 * A merchandising feed is one of the few things in a shop that changes what customers see without
 * anyone touching the admin, so the question "why did this product leave the bestsellers page" has
 * to have an answer that is not "look at the pivot table". The line carries the ids rather than the
 * counts for exactly that: counts say a change happened, ids say which.
 *
 * Refusals are logged at `warning` even when run logging is switched off. A guard that fired
 * silently is indistinguishable from a source that had nothing to do.
 */
class CurationLog
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
    }

    public function logResult(CurationResultInterface $result): void
    {
        if ($result->isRefused()) {
            $this->logger->warning(
                sprintf(
                    'Refused: %s (%s, category %d).',
                    (string) $result->getRefusalReason(),
                    $result->getSourceCode(),
                    $result->getCategoryId()
                )
            );

            return;
        }

        if (!$this->config->isRunLoggingEnabled()) {
            return;
        }

        $this->logger->info(
            sprintf(
                '%s: %s category %d — +%d / -%d / =%d.',
                $result->getSourceCode(),
                $result->isDryRun() ? 'dry run on' : 'reconciled',
                $result->getCategoryId(),
                count($result->getAdded()),
                count($result->getRemoved()),
                count($result->getUnchanged())
            ),
            [
                'added' => $result->getAdded(),
                'removed' => $result->getRemoved(),
                'retained_by_floor' => $result->getRetainedByFloor(),
            ]
        );
    }

    public function logSkipped(string $sourceCode, string $reason): void
    {
        $this->logger->warning(sprintf('Skipped %s: %s.', $sourceCode, $reason));
    }

    public function logFailure(string $sourceCode, \Throwable $exception): void
    {
        $this->logger->error(
            sprintf('%s failed: %s', $sourceCode, $exception->getMessage()),
            ['exception' => $exception]
        );
    }
}
