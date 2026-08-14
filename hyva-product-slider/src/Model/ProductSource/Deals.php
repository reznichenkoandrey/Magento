<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ProductSource;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Model\Config;

/**
 * The deepest discounts, read from the price index rather than from `special_price`.
 *
 * A "deals" slider built on the `special_price` attribute misses most sales on a real shop, because
 * most sales are run as catalogue price rules, which never touch that attribute. It also misses tier
 * pricing and every other mechanism that lands in the final price. `catalog_product_index_price`
 * carries the answer all of them arrive at: `price` (the regular price for the scope) alongside
 * `final_price`, per website and per customer group.
 *
 * Ranking is by relative discount, not absolute, so a 40%-off t-shirt outranks a 5%-off sofa — which
 * is what a shopper scanning a carousel is actually looking for.
 *
 * The customer group is a configured constant rather than the visitor's own, and that is deliberate:
 * the slider is rendered inside a block cache shared by every visitor, so a per-group product list
 * would be correct for whoever warmed the cache and wrong for everybody after.
 */
class Deals extends AbstractSource
{
    public const CODE = 'deals';

    private const TABLE = 'catalog_product_index_price';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string) __('Deals');
    }

    /**
     * @return int[]
     */
    public function getProductIds(SliderInterface $slider, int $storeId, int $limit): array
    {
        try {
            $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();
        } catch (NoSuchEntityException) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['p' => $this->resourceConnection->getTableName(self::TABLE)], ['entity_id'])
            ->where('p.website_id = ?', $websiteId)
            ->where('p.customer_group_id = ?', $this->config->getDealsCustomerGroupId($storeId))
            // A zero or missing regular price makes the ratio meaningless, not infinite.
            ->where('p.price > 0')
            ->where('p.final_price < p.price')
            ->order(new \Zend_Db_Expr('((p.price - p.final_price) / p.price) DESC'))
            ->limit($limit);

        return array_map('intval', $connection->fetchCol($select));
    }
}
