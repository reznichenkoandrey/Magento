<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;

/**
 * Targeted page-cache eviction for the products whose family row changed.
 *
 * This module writes `catalog_product_link` behind the repository's back, which means it also owes
 * the system whatever the repository would have done about the cache. The link table is not
 * unwatched — five views subscribe to it with `entity_column="product_id"`: `catalog_product_price`
 * and `catalog_product_attribute` in `Magento_Catalog`'s `etc/mview.xml`, `catalogsearch_fulltext`,
 * `cataloginventory_stock` and `catalogrule_product` — so under Update on Schedule the write does
 * reach a changelog and the partial reindex that follows would eventually clean the product tags.
 *
 * Eventually is the problem. "Eventually" is the next `cron:run --group=index`, and the family row
 * is rendered live from the link table on every product-page render, so between the reconcile and
 * that cron the cached page and the database disagree. Under Update on Save there is no changelog at
 * all and nothing would evict anything. Neither is a good foundation for "the row is correct now".
 *
 * What core does in this position is register the entity ids on the shared `CacheContext` and
 * dispatch `clean_cache_by_tags` with it — `Magento\Catalog\Plugin\Model\Product\Action\UpdateAttributesFlushCache`
 * is the smallest example, and `Magento\Catalog\Model\Indexer\Product\Category\Action\Rows` the
 * closest one in spirit. `CacheContext::getIdentities()` turns the registered ids into
 * `<cache tag>_<id>` strings, so registering under `Magento\Catalog\Model\Product::CACHE_TAG`
 * ('cat_p') produces the same `cat_p_42` tag a product save would have produced, and every listener
 * of that event — the built-in full page cache via `Magento\PageCache\Observer\FlushCacheByTags`,
 * Varnish via `Magento_CacheInvalidate`, the GraphQL resolver cache — evicts exactly those pages,
 * immediately and in either indexer mode.
 *
 * The alternative, and the reason this class is worth its size, is a full cache flush at the end of
 * a nightly reconcile: a warm full-page cache thrown away because forty products changed places.
 */
class CacheInvalidator
{
    public function __construct(
        private readonly CacheContext $cacheContext,
        private readonly EventManager $eventManager
    ) {
    }

    /**
     * @param int[] $productIds
     */
    public function invalidateProducts(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $this->cacheContext->registerEntities(Product::CACHE_TAG, $productIds);
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
    }
}
