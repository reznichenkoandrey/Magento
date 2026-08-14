<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model;

use Scr1be\CuratedCategories\Api\CurationEngineInterface;
use Scr1be\CuratedCategories\Api\Data\CurationResultInterface;
use Scr1be\CuratedCategories\Api\Data\CurationTargetInterface;
use Scr1be\CuratedCategories\Model\ResourceModel\CategoryMembership;

/**
 * The batch engine. Three verbs, one read and at most two writes each, and no knowledge whatsoever
 * of where the product ids came from.
 *
 * A reconcile is one SELECT of the current membership, a set difference, one
 * `INSERT … ON DUPLICATE KEY UPDATE` and one `DELETE`. That is three statements for a feed of any
 * size — the alternative every merchandising module reaches for first is a loop of category saves,
 * which costs a product load, a category load and a full save per product, and re-registers every
 * indexer once per iteration.
 *
 * ## Why the engine never touches the cache
 *
 * The pivot is not a private table. `Magento\Catalog\Model\Indexer\Product\Category` subscribes to
 * `catalog_category_product` on `product_id` (`vendor/magento/module-catalog/etc/mview.xml`), and so
 * does `catalogsearch_fulltext` (`vendor/magento/module-catalog-search/etc/mview.xml`). Under
 * Update-on-Schedule, every row written here lands in both changelogs through the mview trigger,
 * and the partial reindex that consumes them calls
 * `Magento\Catalog\Model\Indexer\Product\Category::execute()`, which registers `cat_p_<id>` on the
 * indexer cache context. `Magento\Indexer\Model\Processor\CleanCache::afterUpdateMview()` then
 * flushes that context, which dispatches `clean_cache_by_tags` and reaches
 * `Magento\PageCache\Observer\FlushCacheByTags` — a targeted eviction of exactly the pages carrying
 * those product tags.
 *
 * So the engine writes rows and stops. Adding a manual `clean()` on top would evict pages the
 * reindex is about to evict anyway, minutes earlier and with a blunter tag. What that does and does
 * not cover is spelled out in the README rather than assumed here.
 */
class CurationEngine implements CurationEngineInterface
{
    private const REFUSAL_NO_CATEGORY = 'no target category configured';
    private const REFUSAL_EMPTY_SOURCE =
        'source returned no products while the category has %d member(s); refusing to empty it';
    private const REFUSAL_ALL_DELETED =
        'every product the source returned has been deleted; refusing to empty a category with %d member(s)';

    /**
     * The first rank handed out on a full reconcile. Positions are 1-based so that "position 0" keeps
     * its usual meaning of "assigned by hand and never ordered", which is what an untouched pivot row
     * carries.
     */
    private const FIRST_POSITION = 1;

    public function __construct(
        private readonly CategoryMembership $membership,
        private readonly FloorGuard $floorGuard,
        private readonly Config $config
    ) {
    }

    public function reconcileAll(
        CurationTargetInterface $target,
        array $productIds,
        bool $dryRun = false
    ): CurationResultInterface {
        $categoryId = $target->getCategoryId();

        if ($categoryId <= 0) {
            return CurationResult::refused($target, self::REFUSAL_NO_CATEGORY);
        }

        $desired = $this->normalise($productIds);
        $current = $this->membership->getMembership($categoryId);
        $emptyAllowed = $this->config->isEmptySourceAllowed();

        // The misconfiguration guard runs before anything is filtered, so the reason a merchant reads
        // in the log is the one that actually happened: the source came back empty, not that the
        // engine dropped its ids afterwards.
        if ($desired === [] && $current !== [] && !$emptyAllowed) {
            return CurationResult::refused($target, sprintf(self::REFUSAL_EMPTY_SOURCE, count($current)));
        }

        $desired = $this->membership->filterExistingProducts($desired);

        if ($desired === [] && $current !== [] && !$emptyAllowed) {
            return CurationResult::refused($target, sprintf(self::REFUSAL_ALL_DELETED, count($current)));
        }

        $currentIds = array_keys($current);
        $keptFromSource = array_values(array_intersect($desired, $currentIds));
        $added = array_values(array_diff($desired, $currentIds));
        $candidates = array_values(array_diff($currentIds, $desired));

        // An empty source only reaches this line when the merchant has explicitly allowed it, and
        // that permission is worth nothing if the floor then puts four products straight back.
        $floor = ($desired === [] && $emptyAllowed) ? 0 : $target->getMinimumFloor();

        [$removed, $retained] = $this->floorGuard->apply($candidates, $current, count($desired), $floor);

        $unchanged = array_values(array_unique(array_merge($keptFromSource, $retained)));

        if (!$dryRun) {
            $this->membership->upsert($categoryId, $this->buildPositions($desired, $retained));
            $this->membership->remove($categoryId, $removed);
        }

        return CurationResult::of($target, $added, $removed, $unchanged, $retained, $dryRun);
    }

    public function add(
        CurationTargetInterface $target,
        array $productIds,
        bool $dryRun = false
    ): CurationResultInterface {
        $categoryId = $target->getCategoryId();

        if ($categoryId <= 0) {
            return CurationResult::refused($target, self::REFUSAL_NO_CATEGORY);
        }

        $requested = $this->normalise($productIds);

        if ($requested === []) {
            return CurationResult::of($target, [], [], [], [], $dryRun);
        }

        $current = $this->membership->getMembership($categoryId);
        $currentIds = array_keys($current);

        $unchanged = array_values(array_intersect($requested, $currentIds));
        $added = $this->membership->filterExistingProducts(array_values(array_diff($requested, $currentIds)));

        if (!$dryRun && $added !== []) {
            // Appended after the current tail rather than renumbered from one: the incremental path
            // exists to be cheap and to leave a running feed's ranking exactly where the last full
            // reconcile put it.
            $nextPosition = ($current === [] ? 0 : max($current)) + 1;

            $positions = [];
            foreach ($added as $productId) {
                $positions[$productId] = $nextPosition++;
            }

            $this->membership->upsert($categoryId, $positions);
        }

        return CurationResult::of($target, $added, [], $unchanged, [], $dryRun);
    }

    public function remove(
        CurationTargetInterface $target,
        array $productIds,
        bool $dryRun = false
    ): CurationResultInterface {
        $categoryId = $target->getCategoryId();

        if ($categoryId <= 0) {
            return CurationResult::refused($target, self::REFUSAL_NO_CATEGORY);
        }

        $current = $this->membership->getMembership($categoryId);
        $currentIds = array_keys($current);
        $candidates = array_values(array_intersect($this->normalise($productIds), $currentIds));

        if ($candidates === []) {
            return CurationResult::of($target, [], [], $currentIds, [], $dryRun);
        }

        [$removed, $retained] = $this->floorGuard->apply(
            $candidates,
            $current,
            count($current) - count($candidates),
            $target->getMinimumFloor()
        );

        if (!$dryRun && $removed !== []) {
            $this->membership->remove($categoryId, $removed);
        }

        return CurationResult::of(
            $target,
            [],
            $removed,
            array_values(array_diff($currentIds, $removed)),
            $retained,
            $dryRun
        );
    }

    /**
     * Ranked source order becomes `position`, and the products the floor kept back go after it.
     *
     * Interleaving them by old position instead would let a stale member outrank today's number one,
     * which is the one thing a ranked feed cannot survive. The floor's job is to keep the page from
     * being empty, not to keep its old contents on top.
     *
     * @param int[] $desired
     * @param int[] $retained Already lowest-position first, from the floor guard.
     * @return array<int, int> productId => position
     */
    private function buildPositions(array $desired, array $retained): array
    {
        $positions = [];
        $rank = self::FIRST_POSITION;

        foreach ($desired as $productId) {
            $positions[$productId] = $rank++;
        }

        foreach ($retained as $productId) {
            $positions[$productId] = $rank++;
        }

        return $positions;
    }

    /**
     * Integers, positive, deduplicated, first occurrence wins so the source's ranking survives.
     *
     * @param int[]|string[] $productIds
     * @return int[]
     */
    private function normalise(array $productIds): array
    {
        $normalised = [];

        foreach ($productIds as $productId) {
            $id = (int) $productId;

            if ($id > 0) {
                $normalised[$id] = true;
            }
        }

        return array_keys($normalised);
    }
}
