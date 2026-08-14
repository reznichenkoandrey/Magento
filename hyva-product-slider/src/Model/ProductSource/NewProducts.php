<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ProductSource;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;

/**
 * Products inside their "set as new" window.
 *
 * The filter ladder is the one `Magento\Catalog\Block\Product\NewProduct::_getProductCollection()`
 * uses, reproduced deliberately rather than reinvented: `news_from_date` is either null or not in
 * the future, `news_to_date` is either null or not in the past, and **at least one of the two is
 * set**. That last clause is the one worth copying — without it the two `or … is null` branches are
 * satisfied by every product in the catalogue, and the slider becomes "all products, sorted oddly".
 *
 * Both bounds are compared against the store's own day rather than the server's, because a merchant
 * setting a product live "from today" means their today.
 */
class NewProducts extends AbstractSource
{
    public const CODE = 'new';

    public function __construct(
        private readonly CollectionFactory $productCollectionFactory,
        private readonly TimezoneInterface $localeDate
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string) __('New Products');
    }

    /**
     * @return int[]
     */
    public function getProductIds(SliderInterface $slider, int $storeId, int $limit): array
    {
        $startOfToday = $this->localeDate->date()->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $endOfToday = $this->localeDate->date()->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToFilter(
                'news_from_date',
                ['or' => [['date' => true, 'to' => $endOfToday], ['is' => new \Zend_Db_Expr('null')]]],
                'left'
            )
            ->addAttributeToFilter(
                'news_to_date',
                ['or' => [['date' => true, 'from' => $startOfToday], ['is' => new \Zend_Db_Expr('null')]]],
                'left'
            )
            ->addAttributeToFilter(
                [
                    ['attribute' => 'news_from_date', 'is' => new \Zend_Db_Expr('not null')],
                    ['attribute' => 'news_to_date', 'is' => new \Zend_Db_Expr('not null')],
                ]
            )
            ->addAttributeToSort('news_from_date', 'desc')
            ->setPageSize($limit)
            ->setCurPage(1);

        return $this->readIdsInOrder($collection);
    }
}
