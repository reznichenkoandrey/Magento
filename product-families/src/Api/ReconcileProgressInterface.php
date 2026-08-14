<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Api;

/**
 * The reconcile's report of how far it has got, so the CLI can draw a progress bar without the
 * reconcile knowing that Symfony's console exists.
 *
 * The total is only knowable after the scan and the grouping have finished — you cannot count
 * families before you have built them — so `start()` is called part-way through the run rather than
 * at the beginning of it.
 *
 * @api
 */
interface ReconcileProgressInterface
{
    public function start(int $total): void;

    public function advance(int $step = 1): void;

    public function finish(): void;
}
