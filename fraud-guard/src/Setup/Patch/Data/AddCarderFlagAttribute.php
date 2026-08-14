<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the `is_carder` customer attribute.
 *
 * Idempotent by construction: EavSetup::addAttribute() updates the row when the attribute code
 * already exists, and the form assignment is a fixed list rather than an append, so re-running the
 * patch on a partially-applied install converges instead of duplicating.
 */
class AddCarderFlagAttribute implements DataPatchInterface
{
    /**
     * Duplicated from FlagResolver rather than imported: a data patch describes a historical
     * migration and must keep applying identically even if the runtime constant is later renamed.
     */
    private const ATTRIBUTE_CODE = 'is_carder';

    /**
     * Placed just under the customer group selector — the flag is a commercial decision about a
     * customer, and that is where an admin already looks for those.
     */
    private const SORT_ORDER = 29;

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CustomerSetupFactory $customerSetupFactory
    ) {
    }

    public function apply(): self
    {
        /** @var CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $customerSetup->addAttribute(
            Customer::ENTITY,
            self::ATTRIBUTE_CODE,
            [
                'type' => 'int',
                'label' => 'Flagged for card testing',
                // 'boolean' maps to a checkbox in the admin form and to a Yes/No select column in
                // the customer grid; both mappings live in core, so no bespoke UI is needed.
                'input' => 'boolean',
                'source' => Boolean::class,
                'required' => false,
                'default' => '0',
                'visible' => true,
                // is_system defaults to 1 for customer attributes, and a system attribute is
                // excluded from CustomerInterface's custom-attribute metadata — which is what the
                // admin save path uses to accept the posted value. It has to be 0 here. Hiding the
                // flag from the API is handled properly, per-area, by
                // Plugin\Customer\HideFlagFromApiMetadata.
                'system' => false,
                'user_defined' => false,
                'position' => self::SORT_ORDER,
                'sort_order' => self::SORT_ORDER,
                // Grid flags. The customer grid builds its columns from these, so the merchant
                // gets a filterable "Flagged for card testing" column without a listing override.
                'is_used_in_grid' => true,
                'is_visible_in_grid' => true,
                'is_filterable_in_grid' => true,
                'is_searchable_in_grid' => false,
            ]
        );

        $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, self::ATTRIBUTE_CODE);
        // Only the backend customer form. Leaving the storefront forms out is what makes the flag
        // admin-only: a customer editing their account cannot post it, because the value is never
        // extracted for a form it is not registered against.
        $attribute->setData('used_in_forms', ['adminhtml_customer']);
        $attribute->save();

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
