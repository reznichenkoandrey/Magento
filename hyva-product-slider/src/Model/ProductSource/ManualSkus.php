<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ProductSource;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;

/**
 * An explicit, ordered list of SKUs.
 *
 * The one source where the merchandiser, not a query, decides both membership *and* order — which is
 * why the order is reconstructed in PHP after the lookup rather than pushed into SQL. `WHERE sku IN
 * (…)` returns rows in whatever order the storage engine finds them; a `FIELD()` ordering would work
 * on MySQL and quietly stop working on anything else, and the list is at most a few dozen entries.
 *
 * SKUs are matched case-insensitively because that is how Magento itself treats them —
 * `catalog_product_entity.sku` uses the table's default collation, so an admin who typed `24-mb01`
 * into the slider and `24-MB01` into the product form should not get an empty carousel. Unknown SKUs
 * are dropped silently at render time and reported at save time, where somebody can fix them.
 */
class ManualSkus extends AbstractSource
{
    public const CODE = 'manual';

    private const TABLE = 'catalog_product_entity';

    /** A hand-curated list past this length is a category wearing a disguise. */
    private const MAX_SKUS = 100;

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string) __('Manual SKU List');
    }

    public function validateSourceValue(?string $sourceValue): void
    {
        $skus = $this->parseSkus($sourceValue);

        if ($skus === []) {
            throw new LocalizedException(__('Enter at least one SKU for the Manual SKU List source.'));
        }

        if (count($skus) > self::MAX_SKUS) {
            throw new LocalizedException(
                __('A manual SKU list holds at most %1 entries.', self::MAX_SKUS)
            );
        }

        $found = array_map('mb_strtolower', $this->findBySkus($skus));
        $missing = array_filter(
            $skus,
            static fn (string $sku): bool => !in_array(mb_strtolower($sku), $found, true)
        );

        if ($missing !== []) {
            throw new LocalizedException(
                __('These SKUs do not exist: %1', implode(', ', $missing))
            );
        }
    }

    /**
     * @return int[]
     */
    public function getProductIds(SliderInterface $slider, int $storeId, int $limit): array
    {
        $skus = $this->parseSkus($slider->getSourceValue());
        if ($skus === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['e' => $this->resourceConnection->getTableName(self::TABLE)], ['entity_id', 'sku'])
            ->where('e.sku IN (?)', $skus);

        $idsBySku = [];
        foreach ($connection->fetchAll($select) as $row) {
            $idsBySku[mb_strtolower((string) $row['sku'])] = (int) $row['entity_id'];
        }

        $ids = [];
        foreach ($skus as $sku) {
            $key = mb_strtolower($sku);
            if (isset($idsBySku[$key])) {
                $ids[] = $idsBySku[$key];
            }
        }

        return array_slice($ids, 0, $limit);
    }

    /**
     * @return string[]
     */
    private function parseSkus(?string $sourceValue): array
    {
        $skus = [];

        foreach (preg_split('/[,\r\n]+/', (string) $sourceValue) ?: [] as $candidate) {
            $sku = trim($candidate);
            if ($sku !== '') {
                // Keyed by the lower-cased form so a list repeating one SKU renders one slide.
                $skus[mb_strtolower($sku)] = $sku;
            }
        }

        return array_values($skus);
    }

    /**
     * @param string[] $skus
     * @return string[] The SKUs that exist, as stored.
     */
    private function findBySkus(array $skus): array
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['e' => $this->resourceConnection->getTableName(self::TABLE)], ['sku'])
            ->where('e.sku IN (?)', $skus);

        return array_map('strval', $connection->fetchCol($select));
    }
}
