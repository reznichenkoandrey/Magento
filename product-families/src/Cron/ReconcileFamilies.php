<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Cron;

use Scr1be\ProductFamilies\Api\FamilyReconcilerInterface;
use Scr1be\ProductFamilies\Model\Config;
use Scr1be\ProductFamilies\Model\FamilyDefinitionPool;
use Scr1be\ProductFamilies\Model\FamilyLog;

/**
 * The nightly rebuild, behind two gates.
 *
 * The first gate is "is the schedule on". The second is "may it write", and it exists because the
 * first run of this module on a live catalogue is the frightening one: the plan is every link in the
 * catalogue, and the merchant has no way to see it before it happens except by running the CLI. With
 * *Cron dry run* on, the schedule computes the whole plan every night and logs it, and the log is
 * enough to decide. It is the same switch a feature flag would be, spelled as configuration because
 * that is where the rest of the family's settings live.
 *
 * A family that throws does not stop the ones after it. Cron groups run to completion or not at all,
 * and "the colour family had a deleted attribute so nobody got sizes either" is not a trade worth
 * making — the failure is logged with its stack and the next family starts.
 */
class ReconcileFamilies
{
    public function __construct(
        private readonly Config $config,
        private readonly FamilyDefinitionPool $definitionPool,
        private readonly FamilyReconcilerInterface $reconciler,
        private readonly FamilyLog $log
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->isCronEnabled()) {
            return;
        }

        $dryRun = $this->config->isCronDryRun();

        foreach ($this->definitionPool->getFamilyCodes() as $familyCode) {
            try {
                $this->log->logResult($this->reconciler->reconcile($familyCode, $dryRun));
            } catch (\Throwable $error) {
                $this->log->logFailure($familyCode, $error);
            }
        }
    }
}
