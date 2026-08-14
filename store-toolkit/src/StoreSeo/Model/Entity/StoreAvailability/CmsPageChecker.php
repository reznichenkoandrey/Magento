<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Entity\StoreAvailability;

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\ResourceModel\Page as PageResource;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;

/**
 * A CMS page is advertised for a store when it is enabled and assigned to that store.
 *
 * Unlike products and categories, a CMS page has no store-scoped attributes: `is_active` is one
 * value for the page, and store assignment lives in a separate many-to-many table. So the check is
 * two questions rather than one scoped load — PageRepositoryInterface::getById() takes no store
 * argument at all.
 */
class CmsPageChecker implements AvailabilityCheckerInterface
{
    private PageRepositoryInterface $pageRepository;

    private PageResource $pageResource;

    public function __construct(PageRepositoryInterface $pageRepository, PageResource $pageResource)
    {
        $this->pageRepository = $pageRepository;
        $this->pageResource = $pageResource;
    }

    public function isAvailable(int $entityId, int $storeId): bool
    {
        try {
            $page = $this->pageRepository->getById($entityId);
        } catch (NoSuchEntityException $e) {
            return false;
        }

        if (!$page->isActive()) {
            return false;
        }

        // Magento\Cms\Model\ResourceModel\Page::lookupStoreIds() reads cms_page_store, where an
        // "All Store Views" assignment is one row holding store id 0 rather than a row per store.
        // Core treats that id as a wildcard the same way:
        // Magento\Cms\Model\ResourceModel\AbstractCollection::performAddStoreFilter() appends
        // Store::DEFAULT_STORE_ID to the requested store before filtering.
        $assignedStoreIds = array_map('intval', $this->pageResource->lookupStoreIds($entityId));

        return in_array($storeId, $assignedStoreIds, true)
            || in_array((int) Store::DEFAULT_STORE_ID, $assignedStoreIds, true);
    }
}
