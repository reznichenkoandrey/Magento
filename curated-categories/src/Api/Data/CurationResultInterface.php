<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Api\Data;

/**
 * What one reconcile did — or, on a dry run, would have done.
 *
 * The three membership buckets are disjoint and together describe the whole outcome: `added` and
 * `removed` are the two write statements, `unchanged` is everything the engine looked at and left
 * alone. `retainedByFloor` is a fourth, overlapping set — products that belong in `removed` on the
 * source's ranking and are still members because the SEO floor outranked it. Reporting it
 * separately is the difference between a merchant seeing "the feed is short" and seeing nothing at
 * all.
 */
interface CurationResultInterface
{
    public function getCategoryId(): int;

    public function getSourceCode(): string;

    /**
     * @return int[] Product ids that are members now and were not before.
     */
    public function getAdded(): array;

    /**
     * @return int[] Product ids that were members and are not now.
     */
    public function getRemoved(): array;

    /**
     * @return int[] Product ids that were members before and still are. Their position may have been
     *               rewritten to match the source's ranking — the engine reports membership, not
     *               ordering.
     */
    public function getUnchanged(): array;

    /**
     * @return int[] Product ids the source dropped that the floor guard kept anyway.
     */
    public function getRetainedByFloor(): array;

    /**
     * @return bool True when nothing was written, because the caller asked for a plan rather than a
     *              run.
     */
    public function isDryRun(): bool;

    /**
     * @return bool True when a guard stopped the run before any statement was issued. A refused run
     *              is not a failure — nothing threw — so callers have to ask.
     */
    public function isRefused(): bool;

    public function getRefusalReason(): ?string;
}
