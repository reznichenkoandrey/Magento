<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Observer;

use Magento\Catalog\Model\Category;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Scr1be\CategoryCascade\Model\CascadeGuard;
use Scr1be\CategoryCascade\Model\CascadeInvalidator;
use Scr1be\CategoryCascade\Model\CascadeLog;
use Scr1be\CategoryCascade\Model\SubtreeDisabler;

/**
 * Wiring, a guard call, and two failure boundaries — nothing else lives here.
 *
 * The failure boundaries are the reason this class is not a one-liner. By the time a commit-after
 * observer runs, the admin's save is durable and the response is already on its way; throwing from
 * here would show the merchant an error for a save that succeeded, and on the REST path it would
 * turn a 200 into a 500 after the write. So a broken cascade is logged and swallowed, and the
 * catalog is left in the state core produced — parent off, children untouched — which is exactly
 * where a merchant would be without this module installed.
 */
class CascadeDisableSubtree implements ObserverInterface
{
    private const EVENT_DATA_CATEGORY = 'category';

    public function __construct(
        private readonly CascadeGuard $guard,
        private readonly SubtreeDisabler $disabler,
        private readonly CascadeInvalidator $invalidator,
        private readonly CascadeLog $log
    ) {
    }

    public function execute(Observer $observer): void
    {
        $category = $observer->getEvent()->getData(self::EVENT_DATA_CATEGORY);
        if (!$category instanceof Category || !$this->guard->shouldCascade($category)) {
            return;
        }

        $categoryId = (int) $category->getId();
        $storeId = (int) $category->getStoreId();

        try {
            $result = $this->disabler->disableSubtree($category);
        } catch (\Throwable $error) {
            $this->log->cascadeFailed($categoryId, $storeId, $error);
            return;
        }

        if (!$result->hasChanges()) {
            return;
        }

        $this->log->cascadeCompleted($categoryId, $storeId, $result);

        // Separate boundary: a cascade that committed and failed to invalidate is a different
        // incident from one that never wrote anything, and it is fixed by a cache flush rather
        // than by saving the category again.
        try {
            $this->invalidator->invalidate(array_merge([$categoryId], $result->getSubtreeIds()));
        } catch (\Throwable $error) {
            $this->log->cacheInvalidationFailed($categoryId, $error);
        }
    }
}
