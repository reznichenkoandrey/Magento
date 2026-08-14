<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Entity\StoreAvailability;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * A product is advertised for a store when it loads in that store's scope, is enabled there and is
 * visible somewhere a crawler can reach it.
 */
class ProductChecker implements AvailabilityCheckerInterface
{
    private ProductRepositoryInterface $productRepository;

    private Visibility $visibility;

    public function __construct(ProductRepositoryInterface $productRepository, Visibility $visibility)
    {
        $this->productRepository = $productRepository;
        $this->visibility = $visibility;
    }

    public function isAvailable(int $entityId, int $storeId): bool
    {
        try {
            // The third argument of ProductRepositoryInterface::getById() is the store id, so
            // status and visibility come back with the target store's scope applied rather than
            // the current one's — which is the entire reason this check can be trusted.
            $product = $this->productRepository->getById($entityId, false, $storeId);
        } catch (NoSuchEntityException $e) {
            return false;
        }

        if ((int) $product->getStatus() !== Status::STATUS_ENABLED) {
            return false;
        }

        // "Visible in site" rather than "visible in catalog": Visibility::getVisibleInSiteIds()
        // also covers search-only products, which are unlinked but perfectly crawlable and so
        // still worth advertising as an alternate.
        return in_array(
            (int) $product->getVisibility(),
            array_map('intval', $this->visibility->getVisibleInSiteIds()),
            true
        );
    }
}
