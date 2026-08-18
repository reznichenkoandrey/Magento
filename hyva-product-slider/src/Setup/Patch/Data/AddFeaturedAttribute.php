<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The one attribute this module adds: a boolean the Featured source reads.
 *
 * Everything else a slider can select — new, discounted, bestselling, viewed, bought, in a category,
 * in an attribute set — is already recorded somewhere in Magento. "We want this on the home page" is
 * not; it is an editorial decision with no other home, so it gets a column.
 *
 * `used_in_product_listing` matters more than it looks. The column is read by
 * `Catalog\Model\ResourceModel\Config::getAttributesUsedInListing()`, whose `WHERE
 * used_in_product_listing = 1` is the whole selection; `Catalog\Model\Config::getProductAttributes()`
 * caches the resulting codes, and the listing callers — `AbstractProduct::_addProductAttributesAndPrices()`,
 * both `Layer\*\CollectionFilter`s, `ListCompare`, `Crosssell` — pass that list to
 * `addAttributeToSelect()`. `addAttributeToSelect()` itself knows nothing about the flag; it just
 * receives the names. Setting it is therefore what lets a template read the flag off a listing
 * product without a second load.
 */
class AddFeaturedAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'scr1be_featured';

    private const GROUP = 'General';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
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

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // Patches are recorded in `patch_list` and normally run once, but a merchant who restored a
        // database from before the patch and re-ran setup:upgrade should not get a duplicate-code
        // error out of the EAV setup. Re-running this patch on a shop that already has the attribute
        // is then a no-op rather than an overwrite of whatever the merchant configured on it.
        if ($eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE)) {
            $this->moduleDataSetup->getConnection()->endSetup();

            return $this;
        }

        $eavSetup->addAttribute(
            Product::ENTITY,
            self::ATTRIBUTE_CODE,
            [
                'type' => 'int',
                'label' => 'Featured In Sliders',
                'input' => 'boolean',
                'source' => Boolean::class,
                'required' => false,
                'default' => '0',
                'sort_order' => 100,
                'global' => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
                'used_in_product_listing' => true,
                'visible_on_front' => false,
                'user_defined' => true,
                'group' => self::GROUP,
                'is_used_in_grid' => true,
                'is_visible_in_grid' => true,
                'is_filterable_in_grid' => true,
                'note' => 'Included by the Featured product source of Scr1be_HyvaProductSlider.',
            ]
        );

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }
}
