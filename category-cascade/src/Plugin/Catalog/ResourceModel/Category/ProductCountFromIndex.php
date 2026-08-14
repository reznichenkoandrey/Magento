<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Plugin\Catalog\ResourceModel\Category;

use Magento\Catalog\Model\Indexer\Category\Product\Processor as CategoryProductProcessor;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Model\Store;
use Scr1be\CategoryCascade\Model\CascadeLog;
use Scr1be\CategoryCascade\Model\Config;
use Scr1be\CategoryCascade\Model\ResourceModel\IndexedProductCount;

/**
 * Replaces the admin category tree's product counting with one query.
 *
 * Core's Collection::loadProductCount() runs one grouped query for the non-anchor categories and
 * then **one query per anchor category** — each of them a join between the category-product pivot
 * and every descendant path of that category. On a tree where anchoring is the default, that is a
 * query per node, and it is why the admin category page slows to a crawl on a large catalog long
 * before anything else does.
 *
 * Two plugins, for two different reasons:
 *
 * - `around load()` because it is the one seam that can *cancel* the counting instead of running
 *   after it has already cost what it costs, and because the fallback below has to be able to call
 *   loadProductCount() without re-entering this plugin. Hooking loadProductCount() itself would
 *   work — a same-class `$this->` call does go through the interceptor, contrary to the usual
 *   folklore — but it would also intercept our own fallback, which is the recursion this design
 *   exists to avoid.
 * - `before setLoadProductCount()` because the flag it writes has no public getter, and taking
 *   over the counting for a collection that never asked to count would add a query to every
 *   category collection in the application.
 */
class ProductCountFromIndex
{
    /**
     * Collections that asked for product counts, keyed by the collection instance. A WeakMap so a
     * long-lived plugin instance (an application server keeps the object graph between requests)
     * cannot hold a collection alive after the request that built it is gone.
     *
     * @var \WeakMap<Collection, bool>
     */
    private \WeakMap $countRequested;

    public function __construct(
        private readonly Config $config,
        private readonly IndexedProductCount $indexedCount,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly CascadeLog $log
    ) {
        $this->countRequested = new \WeakMap();
    }

    /**
     * Records intent only — the arguments are passed through untouched. The store id is not
     * readable yet at this point: core's own admin block sets the counting flag *before* it sets
     * the store, so every decision that depends on scope has to wait for load().
     *
     * @param bool|int $flag
     */
    public function beforeSetLoadProductCount(Collection $subject, $flag): void
    {
        $this->countRequested[$subject] = (bool) $flag;
    }

    /**
     * @param bool $printQuery
     * @param bool $logQuery
     */
    public function aroundLoad(
        Collection $subject,
        callable $proceed,
        $printQuery = false,
        $logQuery = false
    ) {
        if (!$this->shouldIntercept($subject)) {
            return $proceed($printQuery, $logQuery);
        }

        $storeId = (int) $subject->getStoreId();

        // Core's load() adds these two for its own counting. Adding them here as well keeps the
        // loaded items identical with or without this plugin — and keeps the fallback below able
        // to run core's counting, which reads is_anchor off the items.
        $subject->addAttributeToSelect('all_children');
        $subject->addAttributeToSelect('is_anchor');
        $subject->setLoadProductCount(false);

        $result = $proceed($printQuery, $logQuery);

        try {
            $this->applyIndexedCounts($subject, $storeId);
        } catch (\Throwable $error) {
            // Never worse than core: run exactly the counting that was suppressed. This call is
            // external, so it does reach the collection's own implementation.
            $this->log->productCountFallback($storeId, $error);
            $subject->loadProductCount($subject->getItems(), true, true);
        }

        return $result;
    }

    private function shouldIntercept(Collection $subject): bool
    {
        if (!($this->countRequested[$subject] ?? false) || $subject->isLoaded()) {
            return false;
        }

        if (!$this->config->isIndexedProductCountEnabled()) {
            return false;
        }

        $storeId = (int) $subject->getStoreId();

        // All Store Views has no index table of its own, and inventing one by picking a store
        // would answer a different question than the admin asked.
        if ($storeId === Store::DEFAULT_STORE_ID || !$this->indexedCount->isAvailable($storeId)) {
            return false;
        }

        return $this->isIndexTrustworthy();
    }

    /**
     * An invalidated "Update on Save" index can be arbitrarily stale — a full reindex is pending
     * and nothing is feeding it in the meantime — so the count would be fiction. A scheduled index
     * is a different case: mview is consuming the changelog continuously, so it is current to
     * within a cron run whatever its validity flag says between partial reindexes.
     */
    private function isIndexTrustworthy(): bool
    {
        try {
            $indexer = $this->indexerRegistry->get(CategoryProductProcessor::INDEXER_ID);
        } catch (\Throwable) {
            return false;
        }

        return $indexer->isScheduled() || $indexer->isValid();
    }

    private function applyIndexedCounts(Collection $subject, int $storeId): void
    {
        $items = $subject->getItems();
        if ($items === []) {
            return;
        }

        $counts = $this->indexedCount->fetch($storeId, array_map('intval', array_keys($items)));
        foreach ($items as $item) {
            // Absent from the result set means the index holds no visible product for that
            // category — which is a count of zero, not a missing value.
            $item->setProductCount($counts[(int) $item->getId()] ?? 0);
        }
    }
}
