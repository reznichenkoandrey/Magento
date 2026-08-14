<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Model;

use Psr\Log\LoggerInterface;

/**
 * var/log/category_cascade.log.
 *
 * A cascade rewrites rows the admin never looked at, so "which categories did that save actually
 * touch" has to be answerable after the fact — the admin UI shows one saved category and says
 * nothing about the other forty. The affected ids are the whole point of the entry.
 */
class CascadeLog
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
    }

    public function cascadeCompleted(int $categoryId, int $storeId, CascadeResult $result): void
    {
        if (!$this->config->isCascadeLoggingEnabled($storeId)) {
            return;
        }

        $this->logger->info(
            'Cascaded a category disable to its subtree',
            [
                'category_id' => $categoryId,
                'store_id' => $storeId,
                'subtree_size' => count($result->getSubtreeIds()),
                'disabled_ids' => $result->getDisabledIds(),
                'cleared_store_overrides' => $result->getClearedOverrideRows(),
            ]
        );
    }

    /**
     * Always logged, whatever the logging setting says. A merchant who turned cascade logging off
     * asked for less noise about cascades that worked, not for silence about one that did not —
     * and a failed cascade leaves the catalog in exactly the state the module exists to prevent.
     */
    public function cascadeFailed(int $categoryId, int $storeId, \Throwable $error): void
    {
        $this->logger->error(
            'Category cascade failed; the parent stayed disabled and its subtree did not follow',
            [
                'category_id' => $categoryId,
                'store_id' => $storeId,
                'error' => $error->getMessage(),
                'exception' => $error,
            ]
        );
    }

    public function cacheInvalidationFailed(int $categoryId, \Throwable $error): void
    {
        $this->logger->error(
            'Category cascade completed but its cache invalidation did not',
            [
                'category_id' => $categoryId,
                'error' => $error->getMessage(),
                'exception' => $error,
            ]
        );
    }

    public function indexerInvalidationFailed(string $indexerId, \Throwable $error): void
    {
        $this->logger->error(
            'Category cascade could not invalidate an indexer',
            [
                'indexer_id' => $indexerId,
                'error' => $error->getMessage(),
                'exception' => $error,
            ]
        );
    }

    /**
     * The admin tree asked for index-backed counts and did not get them. Worth a line: the tree
     * still renders correct numbers through core's own counting, just slowly, and "the tree got
     * slow again" is otherwise an unexplainable regression.
     */
    public function productCountFallback(int $storeId, \Throwable $error): void
    {
        $this->logger->warning(
            'Indexed category product count failed; fell back to core counting',
            [
                'store_id' => $storeId,
                'error' => $error->getMessage(),
                'exception' => $error,
            ]
        );
    }
}
