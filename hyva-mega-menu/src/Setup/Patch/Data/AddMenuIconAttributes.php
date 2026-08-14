<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Category\Attribute\Backend\Image as ImageBackend;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Scr1be\HyvaMegaMenu\Model\Icon\IconResolver;

/**
 * The four category attributes the icon ladder reads.
 *
 * The patch is idempotent without a guard of its own: `EavSetup::addAttribute()` looks the
 * attribute up first and updates it when it is already there, so re-running the patch — which is
 * what a `patch_list` row lost to a database restore causes — re-states the definition instead of
 * failing on a duplicate.
 *
 * All four are store-scoped. Icons are merchandising, and a merchant running a seasonal storefront
 * alongside an evergreen one wants the seasonal icon on one and not the other, without duplicating
 * the category.
 *
 * They are `user_defined` so that the admin can see and edit them like any other category
 * attribute, and `visible` so they survive an attribute-set edit — but they are laid out by this
 * module's `category_form.xml` rather than left to be placed automatically, because the category
 * form is an explicitly declared UI component and a fieldset of its own is what keeps four related
 * fields together instead of scattered through General Information.
 */
class AddMenuIconAttributes implements DataPatchInterface
{
    private const GROUP = 'Mega Menu';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach ($this->attributes() as $code => $definition) {
            $eavSetup->addAttribute(Category::ENTITY, $code, $definition + $this->sharedDefinition());
        }

        return $this;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function attributes(): array
    {
        return [
            IconResolver::ATTRIBUTE_SPRITE => [
                'label' => 'Menu Icon (sprite key)',
                'input' => 'text',
                'sort_order' => 10,
                'note' => 'Key of a symbol in the module sprite, for example "tag". Wins over the '
                    . 'other three because a sprite icon inherits the text colour. An unknown key '
                    . 'is ignored rather than drawn, and the next source is used.',
            ],
            IconResolver::ATTRIBUTE_IMAGE => [
                'label' => 'Menu Icon (image)',
                'input' => 'image',
                // The same backend model core uses for the category image: it moves the upload out
                // of the tmp directory on save and stores the file name, which is what
                // Category::getImageUrl() expects to find when the storefront asks for a url.
                'backend' => ImageBackend::class,
                'sort_order' => 20,
                'note' => 'Used when no sprite key is set. A small square works best — the menu '
                    . 'renders it at the same box size as a sprite icon.',
            ],
            IconResolver::ATTRIBUTE_CLASS => [
                'label' => 'Menu Icon (CSS class)',
                'input' => 'text',
                'sort_order' => 30,
                'note' => 'For an icon font or icon set the theme already ships. Letters, digits, '
                    . '-, _, ., : and / only; anything else is ignored.',
            ],
            IconResolver::ATTRIBUTE_COLOR => [
                'label' => 'Menu Icon (colour)',
                'input' => 'text',
                'sort_order' => 40,
                'note' => 'Last resort: a coloured square, as #abc or #aabbcc. Anything that is '
                    . 'not a hex colour is ignored, because the value is written into a CSS '
                    . 'custom property.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedDefinition(): array
    {
        return [
            'type' => 'varchar',
            'required' => false,
            'global' => ScopedAttributeInterface::SCOPE_STORE,
            'group' => self::GROUP,
            'visible' => true,
            'user_defined' => true,
            'is_used_in_grid' => false,
        ];
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
