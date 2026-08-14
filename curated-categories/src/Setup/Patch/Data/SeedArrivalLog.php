<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Scr1be\CuratedCategories\Model\ResourceModel\ArrivalIndex;

/**
 * Backfills the arrival log so the New Arrivals adapter is not blind on the day it is installed.
 *
 * Without this, the adapter has nothing to rank until products start being saved, which on a
 * catalogue that is already live means an empty New page for as long as the window is wide. The
 * backfill uses `catalog_product_entity.created_at`, and that is an **approximation, stated as
 * one**: created_at is when the row was written, not when the product first became buyable, and on a
 * catalogue loaded by a single import every product shares one timestamp. From installation onwards
 * the observer records the real signal, and the seeded rows age out of the window on their own.
 *
 * One statement, `INSERT … SELECT` with IGNORE, so the size of the catalogue costs a table scan
 * rather than a PHP loop, and so re-running it — a reinstall onto a database that kept the table —
 * cannot overwrite a real arrival that the observer has since recorded.
 */
class SeedArrivalLog implements DataPatchInterface
{
    private const PRODUCT_TABLE = 'catalog_product_entity';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();

        $select = $connection->select()
            ->from(
                $this->moduleDataSetup->getTable(self::PRODUCT_TABLE),
                ['entity_id', 'created_at']
            );

        // insertFromSelect() builds the statement, it does not run it — hence the explicit query().
        $connection->query(
            $connection->insertFromSelect(
                $select,
                $this->moduleDataSetup->getTable(ArrivalIndex::TABLE),
                ['product_id', 'arrived_at'],
                AdapterInterface::INSERT_IGNORE
            )
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
