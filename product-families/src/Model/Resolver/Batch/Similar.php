<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model\Resolver\Batch;

use Magento\RelatedProductGraphQl\Model\Resolver\Batch\AbstractLikedProducts;
use Scr1be\ProductFamilies\Model\FamilyLinkType;

/**
 * `ProductInterface.scr1be_similar`.
 *
 * @see OtherColors for why these resolvers carry no logic of their own.
 */
class Similar extends AbstractLikedProducts
{
    protected function getNode(): string
    {
        return 'scr1be_similar';
    }

    protected function getLinkType(): int
    {
        return FamilyLinkType::LINK_TYPE_SIMILAR;
    }
}
