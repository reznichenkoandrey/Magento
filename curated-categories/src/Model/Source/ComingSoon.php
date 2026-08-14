<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model\Source;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\Store;
use Scr1be\CuratedCategories\Model\Config;

/**
 * "What is not here yet but has a date", driven by a product attribute this module installs.
 *
 * The attribute is the feature. A merchant who knows when the container lands can type it into the
 * product and get three things at once: the product on a Coming Soon page, a dated line on its
 * detail page, and both of them disappearing by themselves the morning the date passes. Nothing has
 * to be un-set, which matters because the thing nobody ever does is go back and clear a flag.
 *
 * The query is a collection rather than raw SQL because the filter is an EAV datetime, and
 * `addAttributeToFilter(..., ['date' => true, ...])` is the only place in Magento that knows how
 * those are stored and compared — reimplementing that against `catalog_product_entity_datetime` is
 * how a module ends up a day out for half the year.
 */
class ComingSoon extends AbstractSource
{
    public const CODE = 'coming_soon';

    /**
     * Installed by `Scr1be\CuratedCategories\Setup\Patch\Data\AddRestockDateAttribute`.
     */
    public const ATTRIBUTE_CODE = 'scr1be_restock_date';

    public function __construct(
        Config $config,
        TimezoneInterface $localeDate,
        private readonly CollectionFactory $productCollectionFactory
    ) {
        parent::__construct($config, $localeDate);
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getProductIds(): array
    {
        $collection = $this->productCollectionFactory->create();

        // Default scope, like every other engine input: the pivot the ids end up in has no store
        // column, so a store-scoped restock date could not be honoured even if one were read.
        $collection->setStoreId(Store::DEFAULT_STORE_ID)
            ->addAttributeToSelect(self::ATTRIBUTE_CODE)
            // `from` rather than `gt`: a product restocking today is still coming soon until the day
            // is over, and dropping it at midnight would take it off the page hours before the stock
            // actually lands.
            ->addAttributeToFilter(
                self::ATTRIBUTE_CODE,
                ['date' => true, 'from' => $this->getTodayStartOfDay()]
            )
            // Soonest first — the ranking a shopper on a Coming Soon page is actually looking for.
            ->addAttributeToSort(self::ATTRIBUTE_CODE, 'ASC')
            ->setPageSize($this->config->getLimit(self::CODE))
            ->setCurPage(1);

        $productIds = [];

        foreach ($collection as $product) {
            $productIds[] = (int) $product->getId();
        }

        return $productIds;
    }
}
