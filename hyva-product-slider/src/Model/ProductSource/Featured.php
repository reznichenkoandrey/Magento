<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ProductSource;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Setup\Patch\Data\AddFeaturedAttribute;

/**
 * Products a merchandiser flagged by hand, via the boolean the data patch adds.
 *
 * This is the only source in the module that adds an attribute to the catalogue, and it earns it:
 * every other source derives its answer from data the shop already has, but "we want this on the
 * home page" is an editorial decision that exists nowhere until somebody records it.
 *
 * Ordering is by entity id descending — newest flagged first — because the attribute is a boolean
 * and has no other ranking in it. A merchant who needs an explicit order uses the Manual SKU source,
 * which has one.
 */
class Featured extends AbstractSource
{
    public const CODE = 'featured';

    public function __construct(private readonly CollectionFactory $productCollectionFactory)
    {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string) __('Featured');
    }

    /**
     * @return int[]
     */
    public function getProductIds(SliderInterface $slider, int $storeId, int $limit): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToFilter(AddFeaturedAttribute::ATTRIBUTE_CODE, 1)
            ->addAttributeToSort('entity_id', 'desc')
            ->setPageSize($limit)
            ->setCurPage(1);

        return $this->readIdsInOrder($collection);
    }
}
