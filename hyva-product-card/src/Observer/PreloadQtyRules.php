<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Observer;

use Magento\CatalogInventory\Model\StockRegistryPreloader;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Scr1be\HyvaProductCard\Model\Config;

/**
 * One stock-item query per product listing instead of one per card.
 *
 * Every card asks {@see \Scr1be\HyvaProductCard\Model\Card\QtyRuleResolver} for its stepper rules,
 * which asks `StockRegistryInterface::getStockItem()`, which — on a cold registry — issues a
 * `SELECT` for that single product. Twenty-four cards, twenty-four round trips, none of them
 * visible until someone turns the query log on.
 *
 * `catalog_product_collection_load_after` is the right seam for three reasons. It is dispatched
 * from `Magento\Catalog\Model\ResourceModel\Product\Collection::_afterLoad()`, i.e. *after*
 * pagination, so the id list is the page and not the category. It carries the collection under the
 * key `collection`. And it fires for every path that loads product cards — category listings,
 * search, widgets and the GraphQL data providers all load the same collection class — so one
 * observer covers all four renderers.
 *
 * The preloader is core's own (`Magento\CatalogInventory\Model\StockRegistryPreloader`): it runs a
 * single criteria-filtered `getList()` and writes each row into `StockRegistryStorage` under the
 * default scope id, which is the exact key `StockRegistry::getStockItem()` reads back.
 */
class PreloadQtyRules implements ObserverInterface
{
    public function __construct(
        private readonly StockRegistryPreloader $preloader,
        private readonly Config $config
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $collection = $observer->getData('collection');
        if (!$collection instanceof \Magento\Framework\Data\Collection) {
            return;
        }

        $productIds = [];
        foreach ($collection->getItems() as $item) {
            $id = (int) $item->getId();
            if ($id > 0) {
                $productIds[$id] = $id;
            }
        }

        if ($productIds === []) {
            return;
        }

        // Deliberately unguarded by try/catch. A failure here is a broken stock table, and a card
        // grid that silently renders wrong stepper rules is worse than a page that says so.
        $this->preloader->preloadStockItems(array_values($productIds));
    }
}
