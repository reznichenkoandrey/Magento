<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\Slider;

use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Model\ProductSource\Pool;

/**
 * Turns a slider's chosen source into renderable products, exactly once per slider.
 *
 * Every source answers with ids and stops. This class is the one that knows a carousel must not show
 * a disabled product, a product not visible in the catalogue, a product from another website or —
 * depending on configuration — one that is out of stock. Nine sources each carrying those four rules
 * would be nine chances to forget one, and the one everybody forgets is visibility: a manual SKU list
 * pasted from a spreadsheet routinely contains the simple children of configurables, and they render
 * as cards linking to a 404.
 *
 * Because the filters are applied *after* the source has ranked, the source is asked for more ids
 * than the slider needs. The multiplier is a compromise: too low and a slider full of out-of-stock
 * bestsellers renders half empty, too high and every render sorts a page of the catalogue.
 */
class ProductProvider
{
    private const OVERFETCH_FACTOR = 3;

    /**
     * Absolute ceiling on candidates, regardless of the multiplier. A source is free to be cheap; the
     * `IN (…)` list that follows it is not.
     */
    private const MAX_CANDIDATES = 180;

    public function __construct(
        private readonly Pool $sourcePool,
        private readonly CollectionFactory $productCollectionFactory,
        private readonly CatalogConfig $catalogConfig,
        private readonly Visibility $visibility,
        private readonly Status $status,
        private readonly StockHelper $stockHelper
    ) {
    }

    /**
     * @return Product[] In the source's order, at most `product_limit` of them.
     */
    public function getProducts(SliderInterface $slider, int $storeId): array
    {
        $source = $this->sourcePool->find($slider->getSourceType());
        if ($source === null) {
            return [];
        }

        $limit = max(1, $slider->getProductLimit());
        $candidateIds = $source->getProductIds(
            $slider,
            $storeId,
            min(self::MAX_CANDIDATES, $limit * self::OVERFETCH_FACTOR)
        );

        if ($candidateIds === []) {
            return [];
        }

        $collection = $this->buildCollection($candidateIds, $storeId);
        $loaded = [];
        foreach ($collection as $product) {
            $loaded[(int) $product->getId()] = $product;
        }

        return $this->restoreSourceOrder($candidateIds, $loaded, $limit);
    }

    /**
     * @param int[] $candidateIds
     */
    private function buildCollection(array $candidateIds, int $storeId): ProductCollection
    {
        $collection = $this->productCollectionFactory->create();

        $collection->setStoreId($storeId)
            ->addIdFilter($candidateIds)
            ->addStoreFilter($storeId)
            ->setVisibility($this->visibility->getVisibleInCatalogIds())
            ->addAttributeToFilter('status', ['in' => $this->status->getVisibleStatusIds()]);

        // The same five calls `Catalog\Block\Product\AbstractProduct::_addProductAttributesAndPrices()`
        // makes, and for the same reason: without them a card has no price to render, no rewritten
        // url and none of the attributes `catalog/frontend/list_attributes` promises a listing.
        $collection->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->addAttributeToSelect($this->catalogConfig->getProductAttributes())
            ->addUrlRewrite();

        // `addIsInStockFilterToCollection()` rather than `addInStockFilterToCollection()`: it routes
        // through `CatalogInventory\Model\ResourceModel\Stock\Status::addStockDataToCollection()`,
        // which respects `cataloginventory/options/show_out_of_stock` — so a shop that deliberately
        // lists sold-out products keeps listing them here — and which `Magento_InventoryCatalog`
        // decorates with `AdaptAddStockDataToCollectionPlugin`, making the answer MSI's when MSI is on.
        $this->stockHelper->addIsInStockFilterToCollection($collection);

        return $collection;
    }

    /**
     * @param int[] $candidateIds
     * @param array<int, Product> $loaded
     * @return Product[]
     */
    private function restoreSourceOrder(array $candidateIds, array $loaded, int $limit): array
    {
        $ordered = [];

        foreach ($candidateIds as $candidateId) {
            if (isset($loaded[$candidateId])) {
                $ordered[] = $loaded[$candidateId];
            }

            if (count($ordered) === $limit) {
                break;
            }
        }

        return $ordered;
    }
}
