<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

/**
 * How many GD encodes one request is allowed to perform.
 *
 * Derivatives are produced by the render that first needs them, which is the whole "on-demand" of
 * the module and also its one dangerous property: a CMS page carrying ten uncached images against
 * a six-rung ladder in two formats is a hundred and twenty encodes in a single request. Without a
 * ceiling the first hit after a deploy is a timeout, and the retry behind it is another one,
 * because nothing was finished the first time.
 *
 * The budget makes that request finish. Rungs are requested widest-first, so what a truncated
 * render loses is small rungs — the ones a browser only picks on a narrow viewport, where the
 * fallback is the next size up rather than nothing. The following render picks up where this one
 * stopped, and after two or three hits the page is fully warm.
 *
 * Shared by default in DI, so the counter is per-request, which is the scope that matters.
 */
class EncodeBudget
{
    private int $spent = 0;

    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function tryConsume(?int $storeId = null): bool
    {
        if ($this->spent >= $this->config->getMaxEncodesPerRequest($storeId)) {
            return false;
        }

        ++$this->spent;

        return true;
    }

    public function spent(): int
    {
        return $this->spent;
    }
}
