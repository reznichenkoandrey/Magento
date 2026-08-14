<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ProductSource;

use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;

/**
 * Everything in one category, in the category's own product order.
 *
 * The index table is used rather than the `catalog_category_product` pivot, and the difference is
 * anchor categories: the pivot holds only direct assignments, so a slider pointed at "Women" on the
 * Luma catalogue would come back empty even though the category page shows hundreds of products. The
 * index holds the rolled-up membership the storefront actually renders.
 *
 * The table is resolved through `TableMaintainer::getMainTable($storeId)` rather than named, because
 * the category-product index is dimensioned by store — the physical table is per store id, and
 * hardcoding one name is how a module works on the default store and returns nothing on the second.
 */
class CategoryProducts extends AbstractSource
{
    public const CODE = 'category';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly TableMaintainer $tableMaintainer
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string) __('Category');
    }

    public function validateSourceValue(?string $sourceValue): void
    {
        if ((int) $sourceValue <= 0) {
            throw new LocalizedException(__('Choose a category for the Category source.'));
        }
    }

    /**
     * @return int[]
     */
    public function getProductIds(SliderInterface $slider, int $storeId, int $limit): array
    {
        $categoryId = (int) $slider->getSourceValue();
        if ($categoryId <= 0) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['i' => $this->tableMaintainer->getMainTable($storeId)], ['product_id'])
            ->where('i.category_id = ?', $categoryId)
            ->where('i.store_id = ?', $storeId)
            // The merchandiser's drag-and-drop order in Catalog → Categories → Products in Category.
            ->order('i.position ASC')
            ->limit($limit);

        return array_map('intval', $connection->fetchCol($select));
    }
}
