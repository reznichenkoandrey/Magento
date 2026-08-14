<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\Backend\Datetime;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Scr1be\CuratedCategories\Model\Source\ComingSoon;

/**
 * Installs the product attribute the Coming Soon adapter reads.
 *
 * Idempotent twice over, which is deliberate rather than belt-and-braces. Magento records applied
 * patches in `patch_list` and will not run this a second time, but a module that has been
 * uninstalled and reinstalled — or copied into `app/code` on a database that already carries the
 * attribute — arrives here with the row present.
 * `Magento\Eav\Setup\EavSetup::addAttribute()` handles that itself: it looks the code up and routes
 * to `updateAttribute()` when it finds one, so a second application converges rather than throwing.
 *
 * Two choices in the definition are worth stating:
 *
 * - **Global scope.** The attribute decides membership of `catalog_category_product`, which has no
 *   store column. A per-store restock date would be a promise the storage cannot keep.
 * - **`user_defined => false`.** The module reads this code by name in three places; letting an
 *   admin delete it from the attribute grid would break the adapter and the PDP notice with no
 *   warning anywhere.
 */
class AddRestockDateAttribute implements DataPatchInterface
{
    private const ATTRIBUTE_GROUP = 'Product Details';
    private const SORT_ORDER = 90;

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $eavSetup->addAttribute(
            Product::ENTITY,
            ComingSoon::ATTRIBUTE_CODE,
            [
                'group' => self::ATTRIBUTE_GROUP,
                'type' => 'datetime',
                'label' => 'Expected Restock Date',
                'input' => 'date',
                'backend' => Datetime::class,
                'source' => '',
                'frontend' => '',
                'class' => '',
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'default' => null,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'used_in_product_listing' => true,
                'unique' => false,
                'sort_order' => self::SORT_ORDER,
                'is_used_in_grid' => true,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => true,
            ]
        );

        return $this;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
