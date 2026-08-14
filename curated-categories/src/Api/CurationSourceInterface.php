<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Api;

use Scr1be\CuratedCategories\Api\Data\CurationTargetInterface;

/**
 * One rule for "which products belong in this category".
 *
 * A source answers two questions and does nothing else: where do my products go, and which are
 * they. It never writes, never reads the current membership and never decides whether a change is
 * safe — those belong to the engine, which is why three very different rules (an order aggregate,
 * an arrival log, an attribute scan) share one runner, one floor guard, one CLI and one log.
 *
 * Implementations are registered by code in `di.xml` on `Scr1be\CuratedCategories\Model\SourcePool`.
 */
interface CurationSourceInterface
{
    /**
     * @return string Stable identifier — the CLI argument, the cron job's payload and the log key.
     */
    public function getCode(): string;

    /**
     * @return bool Whether the merchant has switched this source on.
     */
    public function isEnabled(): bool;

    /**
     * @return CurationTargetInterface|null Null when the source is not usable: no category picked,
     *                                      or a category that no longer exists. The runner logs it
     *                                      and moves on rather than treating it as an empty feed.
     */
    public function getTarget(): ?CurationTargetInterface;

    /**
     * @return int[] Product ids, ranked best-first, already capped and already filtered by the
     *               source's own exclusion rules.
     */
    public function getProductIds(): array;
}
