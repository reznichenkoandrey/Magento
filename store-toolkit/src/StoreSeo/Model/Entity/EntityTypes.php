<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Entity;

use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\CmsUrlRewrite\Model\CmsPageUrlRewriteGenerator;

/**
 * One place that knows the `url_rewrite.entity_type` strings this module looks up.
 *
 * These are core constants (ProductUrlRewriteGenerator::ENTITY_TYPE = 'product',
 * CategoryUrlRewriteGenerator::ENTITY_TYPE = 'category', CmsPageUrlRewriteGenerator::ENTITY_TYPE =
 * 'cms-page'), and quoting the constants rather than the literals is the point of this class: the
 * literals are used as array keys in di.xml, as switch labels in the resolver and as query values
 * in the URL lookup, and a hand-typed 'cms_page' in any one of them would fail silently by simply
 * finding no rewrite.
 */
class EntityTypes
{
    public function getProductType(): string
    {
        return ProductUrlRewriteGenerator::ENTITY_TYPE;
    }

    public function getCategoryType(): string
    {
        return CategoryUrlRewriteGenerator::ENTITY_TYPE;
    }

    public function getCmsPageType(): string
    {
        return CmsPageUrlRewriteGenerator::ENTITY_TYPE;
    }
}
