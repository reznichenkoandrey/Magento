<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Settings, and one decision worth stating up front: every value that feeds the engine is read in
 * the **default scope** and never per store.
 *
 * `catalog_category_product` has three columns — entity_id, category_id, product_id — and no store
 * column. Category membership is therefore a global fact, and a per-store "bestsellers category"
 * would be a setting the storage cannot honour: the second store view to run would overwrite the
 * first. The admin form matches, offering no website or store switch on any of the engine sections,
 * so nothing is promised that cannot be kept.
 *
 * The single exception is the coming-soon PDP message, which is a storefront string rather than an
 * engine input, and is read in the scope of the page rendering it.
 *
 * The source group is the source code, so a new adapter needs a config group and nothing here.
 */
class Config
{
    private const XML_PATH_ENGINE = 'scr1be_curated_categories/engine/';
    private const SOURCE_PATH_PREFIX = 'scr1be_curated_categories/';

    private const FIELD_ENABLED = 'enabled';
    private const FIELD_CATEGORY = 'category';
    private const FIELD_LIMIT = 'limit';
    private const FIELD_WINDOW_DAYS = 'window_days';
    private const FIELD_MINIMUM_FLOOR = 'minimum_floor';
    private const FIELD_EXCLUSION_RULES = 'exclusion_rules';
    private const FIELD_EXCLUSION_MATCH = 'exclusion_match';
    private const FIELD_MESSAGE = 'message';

    /**
     * The floor is what stops a curated category from becoming an empty page, so zero is not an
     * option a merchant can type into the field. Turning the guard off is done by turning the
     * source off.
     */
    public const MIN_FLOOR = 1;
    public const DEFAULT_FLOOR = 4;

    /**
     * A feed of one product is a broken feed, and a feed of fifty thousand is a category page nobody
     * paginates to the end of. The bounds exist so a mistyped limit degrades to something sane
     * instead of reconciling the whole catalogue.
     */
    public const MIN_LIMIT = 1;
    public const MAX_LIMIT = 1000;
    public const DEFAULT_LIMIT = 24;

    public const MIN_WINDOW_DAYS = 1;
    public const MAX_WINDOW_DAYS = 365;
    public const DEFAULT_WINDOW_DAYS = 30;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether an empty source may clear a category that currently has members.
     *
     * Off by default, and the reason is the failure it prevents: a source returns nothing far more
     * often because something is misconfigured — an attribute renamed, an exclusion rule that
     * matches everything, an order-status set nobody uses any more — than because the merchant
     * genuinely wants the category emptied. Switching this on says "an empty feed is an
     * instruction", and the floor guard steps aside with it.
     */
    public function isEmptySourceAllowed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENGINE . 'allow_empty_source');
    }

    public function isRunLoggingEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENGINE . 'log_runs');
    }

    public function isSourceEnabled(string $sourceCode): bool
    {
        return $this->scopeConfig->isSetFlag($this->path($sourceCode, self::FIELD_ENABLED));
    }

    /**
     * @return int 0 when no category has been picked — the runner treats that as "not configured"
     *             rather than as category zero.
     */
    public function getCategoryId(string $sourceCode): int
    {
        return (int) $this->scopeConfig->getValue($this->path($sourceCode, self::FIELD_CATEGORY));
    }

    public function getLimit(string $sourceCode): int
    {
        return $this->clamp(
            (int) $this->scopeConfig->getValue($this->path($sourceCode, self::FIELD_LIMIT)),
            self::MIN_LIMIT,
            self::MAX_LIMIT,
            self::DEFAULT_LIMIT
        );
    }

    public function getWindowDays(string $sourceCode): int
    {
        return $this->clamp(
            (int) $this->scopeConfig->getValue($this->path($sourceCode, self::FIELD_WINDOW_DAYS)),
            self::MIN_WINDOW_DAYS,
            self::MAX_WINDOW_DAYS,
            self::DEFAULT_WINDOW_DAYS
        );
    }

    public function getMinimumFloor(string $sourceCode): int
    {
        return max(
            self::MIN_FLOOR,
            (int) $this->scopeConfig->getValue($this->path($sourceCode, self::FIELD_MINIMUM_FLOOR))
        );
    }

    /**
     * The raw dynamic-rows value, straight off `ArraySerialized`.
     *
     * Magento stores a dynamic-rows field as a JSON object keyed by the row's generated id, and
     * hands it back either as that array or — when the row has never been saved — as an empty
     * string, a null or the literal `false` the serialized backend falls back to. Everything except
     * an array is "no rules", which the reader turns into a rule set that excludes nothing.
     *
     * @return array<string, array<string, string>>
     */
    public function getExclusionRules(string $sourceCode): array
    {
        $value = $this->scopeConfig->getValue($this->path($sourceCode, self::FIELD_EXCLUSION_RULES));

        return is_array($value) ? $value : [];
    }

    public function getExclusionMatchMode(string $sourceCode): string
    {
        return (string) $this->scopeConfig->getValue($this->path($sourceCode, self::FIELD_EXCLUSION_MATCH));
    }

    /**
     * The coming-soon PDP line, read in the scope of the storefront rendering it.
     */
    public function getArrivalMessage(string $sourceCode, ?int $storeId = null): string
    {
        return trim(
            (string) $this->scopeConfig->getValue(
                $this->path($sourceCode, self::FIELD_MESSAGE),
                ScopeInterface::SCOPE_STORE,
                $storeId
            )
        );
    }

    private function path(string $sourceCode, string $field): string
    {
        return self::SOURCE_PATH_PREFIX . $sourceCode . '/' . $field;
    }

    private function clamp(int $value, int $min, int $max, int $fallback): int
    {
        if ($value < $min) {
            return $fallback;
        }

        return min($value, $max);
    }
}
