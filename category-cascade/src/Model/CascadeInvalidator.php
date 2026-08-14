<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Indexer\Category\Flat\State as FlatState;
use Magento\Catalog\Model\Indexer\Category\Product\Processor as CategoryProductProcessor;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * Puts back the two things attribute-only writes skip.
 *
 * A model save cleans the entity's cache tags and pokes the indexers on the way out. This module
 * deliberately does not use model saves, so it owes both — otherwise the category pages of a
 * disabled subtree stay in the full page cache and the storefront keeps listing them.
 */
class CascadeInvalidator
{
    private const CLEAN_CACHE_EVENT = 'clean_cache_by_tags';

    public function __construct(
        private readonly CacheContext $cacheContext,
        private readonly EventManager $eventManager,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly FlatState $flatState,
        private readonly CascadeLog $log
    ) {
    }

    /**
     * @param int[] $categoryIds every category whose rendered output may have changed — the whole
     *                           subtree, plus the parent the admin actually saved
     */
    public function invalidate(array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }

        // Entity-tag cleaning rather than a cache flush: FPC and block cache entries carry
        // cat_c_<id> tags, so this evicts exactly the pages that changed and leaves the rest of a
        // warm cache alone. Registering the whole subtree in one context makes it one BAN.
        $this->cacheContext->registerEntities(Category::CACHE_TAG, $categoryIds);
        $this->eventManager->dispatch(self::CLEAN_CACHE_EVENT, ['object' => $this->cacheContext]);

        $this->invalidateIndexer(CategoryProductProcessor::INDEXER_ID);

        // Flat is opt-in and off by default. Invalidating an indexer nobody uses just leaves a red
        // row in the admin grid that no reindex will explain.
        if ($this->flatState->isFlatEnabled()) {
            $this->invalidateIndexer(FlatState::INDEXER_ID);
        }
    }

    /**
     * Only "Update on Save" indexers are invalidated. A scheduled indexer is fed by mview triggers
     * on the same tables this module writes — the changelog already has these rows, and a partial
     * reindex will consume them within the minute. Marking it invalid would replace that with a
     * full rebuild, which on a real catalog is the difference between a minute and an hour, and it
     * would be triggered by the cheapest edit in the admin.
     */
    private function invalidateIndexer(string $indexerId): void
    {
        try {
            $indexer = $this->indexerRegistry->get($indexerId);
            if (!$indexer->isScheduled()) {
                $indexer->invalidate();
            }
        } catch (\Throwable $error) {
            // A missing or unreadable indexer must not undo a cascade that already committed.
            $this->log->indexerInvalidationFailed($indexerId, $error);
        }
    }
}
