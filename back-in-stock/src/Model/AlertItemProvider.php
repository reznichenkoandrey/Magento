<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Status as StockStatusResource;
use Magento\Review\Model\Review;
use Magento\Review\Model\ResourceModel\Review\SummaryFactory as ReviewSummaryFactory;
use Scr1be\BackInStock\Model\ResourceModel\AlertReader;

/**
 * Turns alert rows into cards.
 *
 * The shape of this class is the point of the module's backend half: **four queries, whatever the
 * number of alerts**. One select over `product_alert_stock`; one product collection carrying its
 * price, review and stock-status joins; the single `url_rewrite` lookup `addUrlRewrite()` performs in
 * the collection's `_afterLoad()`; and one stock-item read for the quantity rules. The temptation is
 * a loop — load the product, ask the price model, ask the review helper, ask the stock registry, ask
 * the url finder — and that is four queries *per card* on a surface whose whole promise is that it
 * appears instantly.
 *
 * @see AlertReader for why the alert rows are read raw rather than as alert models.
 */
class AlertItemProvider
{
    /**
     * Attributes the cards actually render. Naming them keeps the EAV join list short — a bare
     * collection would join every attribute in the default group.
     */
    private const CARD_ATTRIBUTES = [
        'name',
        'small_image',
        'small_image_label',
        'url_key',
        'news_from_date',
        'news_to_date',
    ];

    public function __construct(
        private readonly AlertReader $alertReader,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ReviewSummaryFactory $reviewSummaryFactory,
        private readonly StockStatusResource $stockStatusResource,
        private readonly StockItemCriteriaInterfaceFactory $stockItemCriteriaFactory,
        private readonly StockItemRepositoryInterface $stockItemRepository,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly BadgeResolver $badgeResolver,
        private readonly Config $config
    ) {
    }

    /**
     * The alerts the popup owes this customer.
     *
     * @return AlertItem[]
     */
    public function getQueued(AlertScope $scope): array
    {
        if (!$scope->isIdentified()) {
            return [];
        }

        $rows = $this->alertReader->readQueued(
            $scope->customerId,
            $scope->websiteId,
            $this->config->getMaxItems($scope->storeId)
        );

        return $this->build($rows, $scope);
    }

    /**
     * Every stock alert the customer holds — the account page and the GraphQL query.
     *
     * @return AlertItem[]
     */
    public function getAll(AlertScope $scope): array
    {
        if (!$scope->isIdentified()) {
            return [];
        }

        return $this->build($this->alertReader->readAll($scope->customerId, $scope->websiteId), $scope);
    }

    /**
     * @param array<int, array<string, int|string|null>> $rows
     * @return AlertItem[]
     */
    private function build(array $rows, AlertScope $scope): array
    {
        if ($rows === []) {
            return [];
        }

        $productIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int)$row['product_id'],
            $rows
        )));

        $products = $this->loadProducts($productIds, $scope);

        if ($products === []) {
            return [];
        }

        $stockItems = $this->loadStockItems(array_keys($products));
        $threshold = $this->config->getLowStockThreshold($scope->storeId);

        $items = [];

        foreach ($rows as $row) {
            $productId = (int)$row['product_id'];

            // An alerted product that the collection did not return — disabled since, unassigned
            // from the website, or gone from the price index — is dropped rather than rendered as a
            // card with no price on it. The alert row stays: the product may come back.
            if (!isset($products[$productId])) {
                continue;
            }

            $items[] = $this->buildItem(
                $row,
                $products[$productId],
                $stockItems[$productId] ?? null,
                $threshold,
                $scope
            );
        }

        return $items;
    }

    /**
     * The one collection, with everything joined onto it before it loads.
     *
     * @param int[] $productIds
     * @return array<int, Product>
     */
    private function loadProducts(array $productIds, AlertScope $scope): array
    {
        /** @var ProductCollection $collection */
        $collection = $this->productCollectionFactory->create();
        $collection->setStore($scope->storeId);
        $collection->addAttributeToSelect(self::CARD_ATTRIBUTES);
        $collection->addIdFilter($productIds);
        $collection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);

        // Rewritten product urls, so a card links where the storefront links. Not a join: the
        // collection's `_afterLoad()` runs one `findAllByData()` over `url_rewrite` for every product
        // it loaded and stamps `request_path` on each. Skipping it would make
        // `Product\Url::getUrl()` issue its own `findOneByData()` per card instead.
        $collection->addUrlRewrite();

        // `catalog_product_index_price` for this group and website: `price`, `final_price`,
        // `minimal_price`, `min_price`, `max_price`, `tier_price`. The join core builds here is an
        // inner one, which is also the website filter — a product not assigned to the website has no
        // row in the index for it and falls out of the collection.
        $collection->addPriceData($scope->customerGroupId, $scope->websiteId);

        // `reviews_count` and `rating_summary`, IFNULL'd to zero, as a left join on
        // `review_entity_summary`. Same call `Magento\Review\Observer\CatalogProductListCollectionAppendSummaryFieldsObserver`
        // makes on a category listing, and it has to happen while the collection is unloaded — the
        // core method checks `isLoaded()` and silently does nothing otherwise.
        $this->reviewSummaryFactory->create()->appendSummaryFieldsToCollection(
            $collection,
            $scope->storeId,
            Review::ENTITY_PRODUCT_CODE
        );

        // `is_salable`, left-joined so an out-of-stock product still comes back and renders without
        // a buy button rather than disappearing. Passing `false` for `$isFilterInStock` is what
        // chooses the left join; Magento_InventoryCatalog's `AdaptAddStockDataToCollectionPlugin`
        // takes the same flag and points the join at the stock index of the current website, so this
        // one call is correct on single- and multi-source installations alike.
        $this->stockStatusResource->addStockDataToCollection($collection, false);

        $products = [];

        foreach ($collection as $product) {
            /** @var Product $product */
            $products[(int)$product->getId()] = $product;
        }

        return $products;
    }

    /**
     * Quantity rules for every product on the page, in one query.
     *
     * `setScopeFilter()` takes the stock item's `website_id`, which is not the website the customer
     * is on: `Magento\CatalogInventory\Model\Configuration::getDefaultScopeId()` returns 0 and every
     * stock item is written against it. Asking for the real website id here returns nothing at all.
     *
     * @param int[] $productIds
     * @return array<int, StockItemInterface>
     */
    private function loadStockItems(array $productIds): array
    {
        $criteria = $this->stockItemCriteriaFactory->create();
        $criteria->setProductsFilter($productIds);
        $criteria->setScopeFilter($this->stockConfiguration->getDefaultScopeId());

        $items = [];

        foreach ($this->stockItemRepository->getList($criteria)->getItems() as $stockItem) {
            /** @var StockItemInterface $stockItem */
            $items[(int)$stockItem->getProductId()] = $stockItem;
        }

        return $items;
    }

    /**
     * @param array<string, int|string|null> $row
     */
    private function buildItem(
        array $row,
        Product $product,
        ?StockItemInterface $stockItem,
        int $threshold,
        AlertScope $scope
    ): AlertItem {
        $finalPrice = (float)$product->getData('final_price');
        $regularPrice = (float)$product->getData('price');
        $stockQty = $stockItem !== null ? (float)$stockItem->getQty() : 0.0;

        $typeInstance = $product->getTypeInstance();

        return new AlertItem(
            (int)$row['alert_stock_id'],
            $product,
            // `readQueued()` selects neither column, because the query it runs already pins both:
            // a row it returns is sent and queued by definition.
            (int)($row['status'] ?? AlertState::ALERT_SENT),
            (int)($row['popup_status'] ?? AlertState::POPUP_QUEUED),
            isset($row['add_date']) ? (string)$row['add_date'] : null,
            isset($row['send_date']) ? (string)$row['send_date'] : null,
            $finalPrice,
            $regularPrice,
            (int)round((float)$product->getData('rating_summary')),
            (int)$product->getData('reviews_count'),
            (bool)(int)$product->getData('is_salable'),
            // Two different reasons a card cannot post a cart line, treated as one because the
            // remedy is the same: send the customer to the product page, where the options are.
            $typeInstance->isComposite($product) || $typeInstance->hasRequiredOptions($product),
            $this->readQtyRules($stockItem),
            $this->badgeResolver->resolve(
                $product,
                $stockQty,
                $finalPrice,
                $regularPrice,
                $threshold,
                $scope->storeId
            )
        );
    }

    /**
     * `Magento\CatalogInventory\Model\Stock\Item`'s getters already fold in the
     * `use_config_*` fallbacks and the store-scoped configuration behind them, so the rules come out
     * of here resolved rather than as a pair of "value plus whether to believe it".
     */
    private function readQtyRules(?StockItemInterface $stockItem): QtyRules
    {
        if ($stockItem === null) {
            return QtyRules::unrestricted();
        }

        return new QtyRules(
            (float)$stockItem->getMinSaleQty(),
            (float)$stockItem->getMaxSaleQty(),
            // False rather than a number when increments are switched off, hence the cast.
            (float)$stockItem->getQtyIncrements(),
            (bool)$stockItem->getIsQtyDecimal()
        );
    }
}
