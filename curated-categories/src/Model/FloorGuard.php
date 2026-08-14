<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model;

/**
 * The SEO floor: a curated category may shrink, but it may not become an empty page.
 *
 * A category that lists nothing is a thin page with internal links pointing at it, and search
 * engines treat the two states — "briefly empty" and "not worth keeping in the index" — the same
 * way. Bestsellers over a quiet month, new arrivals over a slow week and a coming-soon feed the day
 * everything lands are all ordinary reasons for a feed to run dry, and none of them is a reason to
 * publish a blank page.
 *
 * One rule covers both call paths, which is why there is one class rather than two branches in the
 * engine: **the members kept back are the ones with the lowest position**. On a full reconcile that
 * means the survivors are whatever ranked highest last time the source was healthy; on an
 * incremental remove it is the same statement read from the other end — removal starts at the
 * highest position and works up. Position is the source's own ranking, so the floor keeps the best
 * of the old feed rather than an arbitrary slice of it.
 */
class FloorGuard
{
    /**
     * Decide which of the removal candidates actually go.
     *
     * @param int[] $removalCandidates Product ids the caller wants gone.
     * @param array<int, int> $currentPositions productId => position, for the whole current membership.
     * @param int $survivorCount How many members remain if every candidate is removed.
     * @param int $floor The minimum the category must keep.
     * @return array{0: int[], 1: int[]} [removed, retainedByFloor]
     */
    public function apply(
        array $removalCandidates,
        array $currentPositions,
        int $survivorCount,
        int $floor
    ): array {
        $deficit = $floor - $survivorCount;

        if ($deficit <= 0 || $removalCandidates === []) {
            return [array_values($removalCandidates), []];
        }

        $ordered = $this->orderByPosition($removalCandidates, $currentPositions);

        $retained = array_slice($ordered, 0, min($deficit, count($ordered)));
        $removed = array_slice($ordered, count($retained));

        return [$removed, $retained];
    }

    /**
     * Lowest position first.
     *
     * The product id is the tie-break rather than leaving `usort` to decide, because two members can
     * share a position — nothing in the pivot's schema prevents it, and a merchant who assigned the
     * category by hand before switching a source on will have several rows at 0. Without the
     * tie-break the same input could retain different products on different runs, which is exactly
     * the kind of instability an SEO guard exists to prevent.
     *
     * @param int[] $productIds
     * @param array<int, int> $currentPositions
     * @return int[]
     */
    private function orderByPosition(array $productIds, array $currentPositions): array
    {
        $ordered = array_values($productIds);

        usort(
            $ordered,
            static function (int $left, int $right) use ($currentPositions): int {
                $byPosition = ($currentPositions[$left] ?? 0) <=> ($currentPositions[$right] ?? 0);

                return $byPosition !== 0 ? $byPosition : $left <=> $right;
            }
        );

        return $ordered;
    }
}
