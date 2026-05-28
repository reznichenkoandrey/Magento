<?php
declare(strict_types=1);

namespace Scr1be\MegaMenuAttributes\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Category\Attribute\Backend\Image as ImageBackend;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddMegamenuFeaturedImageAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'megamenu_featured_image';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory,
    ) {
    }

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $eavSetup->addAttribute(
            Category::ENTITY,
            self::ATTRIBUTE_CODE,
            [
                'type'            => 'varchar',
                'label'           => 'Mega Menu Featured Image',
                'input'           => 'image',
                'backend'         => ImageBackend::class,
                'required'        => false,
                'sort_order'      => 100,
                'global'          => ScopedAttributeInterface::SCOPE_STORE,
                'group'           => 'General Information',
                'visible'         => true,
                'user_defined'    => true,
                'is_used_in_grid' => false,
                'note'            => 'Displayed in the mega menu panel for this category. Recommended size: 400×400.',
            ]
        );

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
