<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Api;

use Scr1be\ProductFamilies\Api\Data\ReconcileResultInterface;

/**
 * Rebuild one family's links from the catalogue.
 *
 * The dry run is not a separate code path: it takes the identical route up to and not including the
 * writer, so what it reports is what a real run would have done rather than a second
 * implementation's opinion of it.
 *
 * @api
 */
interface FamilyReconcilerInterface
{
    /**
     * @param string $familyCode one of the codes in `Model\FamilyLinkType`
     * @param bool $dryRun compute the plan, write nothing, invalidate nothing
     * @param ReconcileProgressInterface|null $progress optional observer for long runs
     * @throws \Magento\Framework\Exception\LocalizedException when a configured attribute is missing
     */
    public function reconcile(
        string $familyCode,
        bool $dryRun = false,
        ?ReconcileProgressInterface $progress = null
    ): ReconcileResultInterface;
}
