<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model;

use Psr\Log\LoggerInterface;
use Scr1be\ProductFamilies\Api\Data\ReconcileResultInterface;

/**
 * One line per family per run, in `var/log/product_families.log`.
 *
 * Links appear on product pages without anyone having touched the admin, so "why does this hoodie
 * suddenly suggest that hoodie" needs an answer that is not "read the link table". The line carries
 * the affected ids, not just the counts: counts say a change happened, ids say to whom.
 *
 * Refusals are logged at `warning` regardless of the run-logging switch. A family that quietly did
 * nothing because it is half-configured looks exactly like a family that had nothing to do, and the
 * difference matters at three in the morning.
 */
class FamilyLog
{
    /**
     * The ids go into the log context, which ends up in the line as JSON. A first run over a large
     * catalogue affects every product in it, and a log line with fifty thousand ids in it is a log
     * line nobody reads.
     */
    private const MAX_LOGGED_IDS = 200;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function logResult(ReconcileResultInterface $result): void
    {
        if ($result->isRefused()) {
            $this->logger->warning(
                sprintf('%s: refused — %s.', $result->getFamilyCode(), (string)$result->getRefusalReason())
            );

            return;
        }

        $affected = $result->getAffectedProductIds();

        $this->logger->info(
            sprintf(
                '%s: %s %d families / %d memberships — +%d ~%d -%d =%d, %d product(s) invalidated.',
                $result->getFamilyCode(),
                $result->isDryRun() ? 'dry run over' : 'reconciled',
                $result->getFamilyCount(),
                $result->getMemberCount(),
                $result->getInsertedCount(),
                $result->getUpdatedCount(),
                $result->getDeletedCount(),
                $result->getUnchangedCount(),
                count($affected)
            ),
            [
                'affected_product_ids' => array_slice($affected, 0, self::MAX_LOGGED_IDS),
                'affected_truncated' => max(0, count($affected) - self::MAX_LOGGED_IDS),
            ]
        );
    }

    public function logFailure(string $familyCode, \Throwable $error): void
    {
        $this->logger->error(
            sprintf('%s: reconcile failed — %s', $familyCode, $error->getMessage()),
            ['exception' => $error]
        );
    }
}
