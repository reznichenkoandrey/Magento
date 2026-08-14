<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Entity\StoreAvailability;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * A category is advertised for a store when it loads in that store's scope and is active there.
 */
class CategoryChecker implements AvailabilityCheckerInterface
{
    private CategoryRepositoryInterface $categoryRepository;

    public function __construct(CategoryRepositoryInterface $categoryRepository)
    {
        $this->categoryRepository = $categoryRepository;
    }

    public function isAvailable(int $entityId, int $storeId): bool
    {
        try {
            // CategoryRepositoryInterface::get() takes the store id as its second argument, so
            // is_active is read at the target store's scope — the attribute is store-scoped and a
            // category switched off for one store view only is the case this exists to catch.
            $category = $this->categoryRepository->get($entityId, $storeId);
        } catch (NoSuchEntityException $e) {
            return false;
        }

        return (bool) $category->getIsActive();
    }
}
