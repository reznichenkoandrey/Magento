<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model\Resolver\Batch;

use Magento\RelatedProductGraphQl\Model\Resolver\Batch\AbstractLikedProducts;
use Scr1be\ProductFamilies\Model\FamilyLinkType;

/**
 * `ProductInterface.scr1be_other_sizes`.
 *
 * @see OtherColors for why these resolvers carry no logic of their own.
 */
class OtherSizes extends AbstractLikedProducts
{
    protected function getNode(): string
    {
        return 'scr1be_other_sizes';
    }

    protected function getLinkType(): int
    {
        return FamilyLinkType::LINK_TYPE_OTHER_SIZES;
    }
}
