<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Setup\Patch\Data;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Scr1be\ProductFamilies\Model\FamilyLinkType;

/**
 * Seeds the three link types and their `position` attributes.
 *
 * The shape is core's. `Magento\Catalog\Setup\Patch\Data\InstallDefaultCategories` writes
 * `catalog_product_link_type` with `insertForce()` so that `relation`, `up_sell` and `cross_sell`
 * land on the ids their constants promise, and follows it with one `catalog_product_link_attribute`
 * row per type — `product_link_attribute_code` 'position', `data_type` 'int'. This patch does the
 * same for `scr1be_other_colors`, `scr1be_other_sizes` and `scr1be_similar`.
 *
 * What core does not have to deal with is a collision, and this is where the patch earns its keep.
 * Reserving an auto-increment id is a bet that nothing else took it, so the patch checks both
 * directions before writing and **refuses rather than guesses**:
 *
 * - our code already present under a different id → another installation of this module ran against
 *   a database where the reservation failed; the module's constants would silently address the wrong
 *   type, so stop;
 * - our id already present under a different code → an extension reserved it first; forcing it would
 *   take over its links, so stop.
 *
 * Both cases print what they found. A merchant can then decide, which is the only correct behaviour
 * available — the ids are compiled into the GraphQL resolvers, so the patch cannot quietly relocate
 * itself.
 *
 * Re-running is a no-op: everything is checked for presence first, which is what makes the patch
 * safe when `setup:upgrade` runs after a `patch_list` row is lost with a database restore.
 */
class InstallFamilyLinkTypes implements DataPatchInterface
{
    private const LINK_TYPE_TABLE = 'catalog_product_link_type';
    private const LINK_ATTRIBUTE_TABLE = 'catalog_product_link_attribute';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly FamilyLinkType $linkTypes
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * No previous names — the module has only ever had this one. The method is part of
     * `Magento\Framework\Setup\Patch\PatchInterface` and exists so a renamed patch class can tell
     * `patch_list` that it has already run under its old name.
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @throws LocalizedException
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $typeTable = $this->moduleDataSetup->getTable(self::LINK_TYPE_TABLE);
        $attributeTable = $this->moduleDataSetup->getTable(self::LINK_ATTRIBUTE_TABLE);

        $this->moduleDataSetup->startSetup();

        foreach ($this->linkTypes->getReservedTypes() as $linkTypeId => $code) {
            $idForCode = $connection->fetchOne(
                $connection->select()->from($typeTable, ['link_type_id'])->where('code = ?', $code)
            );

            if ($idForCode !== false && $idForCode !== null && $idForCode !== '') {
                if ((int)$idForCode !== $linkTypeId) {
                    throw new LocalizedException(
                        __(
                            'Product link type "%1" already exists with id %2, but this module addresses '
                            . 'it as %3. Remove the stale row or reinstall the module against a clean '
                            . 'catalog_product_link_type table.',
                            $code,
                            (int)$idForCode,
                            $linkTypeId
                        )
                    );
                }
            } else {
                $codeForId = $connection->fetchOne(
                    $connection->select()->from($typeTable, ['code'])->where('link_type_id = ?', $linkTypeId)
                );

                if ($codeForId !== false && $codeForId !== null && $codeForId !== '') {
                    throw new LocalizedException(
                        __(
                            'Product link type id %1 is reserved by this module but already used by "%2". '
                            . 'Two extensions cannot share a link type id.',
                            $linkTypeId,
                            (string)$codeForId
                        )
                    );
                }

                $connection->insertForce($typeTable, ['link_type_id' => $linkTypeId, 'code' => $code]);
            }

            $this->ensurePositionAttribute($attributeTable, $linkTypeId);
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * The position attribute id is an auto-increment and is never referenced by a constant — the
     * writer looks it up by (link type, code) — so nothing has to be reserved here, only present.
     */
    private function ensurePositionAttribute(string $attributeTable, int $linkTypeId): void
    {
        $connection = $this->moduleDataSetup->getConnection();

        $existing = $connection->fetchOne(
            $connection->select()
                ->from($attributeTable, ['product_link_attribute_id'])
                ->where('link_type_id = ?', $linkTypeId)
                ->where('product_link_attribute_code = ?', FamilyLinkType::POSITION_ATTRIBUTE_CODE)
        );

        if ($existing !== false && $existing !== null && $existing !== '') {
            return;
        }

        $connection->insert(
            $attributeTable,
            [
                'link_type_id' => $linkTypeId,
                'product_link_attribute_code' => FamilyLinkType::POSITION_ATTRIBUTE_CODE,
                'data_type' => FamilyLinkType::POSITION_ATTRIBUTE_DATA_TYPE,
            ]
        );
    }
}
