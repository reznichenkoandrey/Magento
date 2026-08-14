<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model\Resolver\Batch;

use Magento\RelatedProductGraphQl\Model\Resolver\Batch\AbstractLikedProducts;
use Scr1be\ProductFamilies\Model\FamilyLinkType;

/**
 * `ProductInterface.scr1be_other_colors`.
 *
 * The whole resolver is two methods because `Magento\RelatedProductGraphQl`'s batch base class is
 * link-type agnostic: it takes the requests for a batch of products, asks
 * `RelatedProductDataProvider::getRelations()` for their links of the given type — which orders by
 * `position` — narrows the result to the current website through `RelatedProductsByStoreId`, loads
 * the requested fields with one `ProductDataProvider::getList()` call, and maps the products back to
 * their requests. All of that is exactly what these three fields need, and none of it is specific to
 * `relation`, `up_sell` or `cross_sell`.
 *
 * `getLinkType()` is declared `: int` there, which is why this module reserves its link type ids
 * instead of resolving them by code — see `Model\FamilyLinkType`.
 */
class OtherColors extends AbstractLikedProducts
{
    protected function getNode(): string
    {
        return 'scr1be_other_colors';
    }

    protected function getLinkType(): int
    {
        return FamilyLinkType::LINK_TYPE_OTHER_COLORS;
    }
}
