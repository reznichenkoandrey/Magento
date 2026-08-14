<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Api;

use Scr1be\CuratedCategories\Api\Data\CurationResultInterface;
use Scr1be\CuratedCategories\Api\Data\CurationTargetInterface;

/**
 * The whole write surface of this module: three verbs over the category-product pivot.
 *
 * Every implementation must be safe to call from cron, CLI, an observer and a REST controller
 * without any of them knowing about the others, and must never throw for a merchandising reason —
 * a source that returns nothing, a category that would be emptied, a product that no longer exists
 * are all outcomes, reported on the result, not exceptions. Exceptions are reserved for the
 * database being unavailable.
 */
interface CurationEngineInterface
{
    /**
     * Make the category's membership exactly $productIds, in that order.
     *
     * @param CurationTargetInterface $target
     * @param int[] $productIds Ranked best-first; the order becomes the pivot's `position`.
     * @param bool $dryRun Compute the plan, write nothing.
     * @return CurationResultInterface
     */
    public function reconcileAll(
        CurationTargetInterface $target,
        array $productIds,
        bool $dryRun = false
    ): CurationResultInterface;

    /**
     * Append products to the category, leaving every existing member and position alone.
     *
     * @param CurationTargetInterface $target
     * @param int[] $productIds
     * @param bool $dryRun
     * @return CurationResultInterface
     */
    public function add(
        CurationTargetInterface $target,
        array $productIds,
        bool $dryRun = false
    ): CurationResultInterface;

    /**
     * Drop products from the category, subject to the floor.
     *
     * @param CurationTargetInterface $target
     * @param int[] $productIds
     * @param bool $dryRun
     * @return CurationResultInterface
     */
    public function remove(
        CurationTargetInterface $target,
        array $productIds,
        bool $dryRun = false
    ): CurationResultInterface;
}
