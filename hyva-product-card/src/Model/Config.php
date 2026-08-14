<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Every knob the card reads, in one place, already sanitised.
 *
 * The card is assembled by six collaborators that all need the same three or four settings; if
 * each of them read `scope_config` directly, each of them would also own a copy of "what does an
 * empty srcset field mean" and they would drift. This class is the only thing in the module that
 * knows a config path exists.
 */
class Config
{
    private const PATH_ENABLED = 'scr1be_product_card/general/enabled';

    private const PATH_BADGE_NEW = 'scr1be_product_card/badges/new_enabled';
    private const PATH_BADGE_SALE = 'scr1be_product_card/badges/sale_enabled';
    private const PATH_BADGE_SALE_MIN_PERCENT = 'scr1be_product_card/badges/sale_min_percent';
    private const PATH_BADGE_LOW_STOCK = 'scr1be_product_card/badges/low_stock_enabled';
    private const PATH_LOW_STOCK_THRESHOLD = 'scr1be_product_card/badges/low_stock_threshold';

    private const PATH_SRCSET_WIDTHS = 'scr1be_product_card/media/srcset_widths';
    private const PATH_SIZES = 'scr1be_product_card/media/sizes';
    private const PATH_HOVER_ENABLED = 'scr1be_product_card/media/hover_enabled';
    private const PATH_HOVER_MAX_PRODUCTS = 'scr1be_product_card/media/hover_max_products';

    private const PATH_STOCK_TTL = 'scr1be_product_card/stock/endpoint_ttl';

    private const PATH_GA4_ENABLED = 'scr1be_product_card/analytics/ga4_enabled';

    /**
     * Used whenever the admin field is empty or unparseable. A card without an image ladder is a
     * layout regression, so the fallback is a working ladder rather than an empty one.
     */
    private const DEFAULT_SRCSET_WIDTHS = [240, 320, 480, 640];

    /** Widths outside this range are typos, not intentions: a 12px or 6000px card image is neither. */
    private const MIN_IMAGE_WIDTH = 40;
    private const MAX_IMAGE_WIDTH = 2400;

    /** More rungs than this stop helping the browser and start filling the media cache. */
    private const MAX_SRCSET_RUNGS = 6;

    private const MAX_DISCOUNT_PERCENT = 100.0;

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_ENABLED, $storeId);
    }

    public function isNewBadgeEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_BADGE_NEW, $storeId);
    }

    public function isSaleBadgeEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_BADGE_SALE, $storeId);
    }

    public function isLowStockBadgeEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_BADGE_LOW_STOCK, $storeId);
    }

    /**
     * @return float Percentage points, clamped to 0-100.
     */
    public function getSaleMinPercent(?int $storeId = null): float
    {
        $value = (float) $this->value(self::PATH_BADGE_SALE_MIN_PERCENT, $storeId);

        return max(0.0, min(self::MAX_DISCOUNT_PERCENT, $value));
    }

    public function getLowStockThreshold(?int $storeId = null): float
    {
        return max(0.0, (float) $this->value(self::PATH_LOW_STOCK_THRESHOLD, $storeId));
    }

    /**
     * @return int[] Ascending, de-duplicated, sanity-bounded pixel widths.
     */
    public function getSrcsetWidths(?int $storeId = null): array
    {
        $raw = (string) $this->value(self::PATH_SRCSET_WIDTHS, $storeId);

        $widths = [];
        foreach (explode(',', $raw) as $candidate) {
            $width = (int) trim($candidate);
            if ($width >= self::MIN_IMAGE_WIDTH && $width <= self::MAX_IMAGE_WIDTH) {
                $widths[$width] = $width;
            }
        }

        if ($widths === []) {
            return self::DEFAULT_SRCSET_WIDTHS;
        }

        sort($widths);

        return array_slice($widths, 0, self::MAX_SRCSET_RUNGS);
    }

    public function getSizesAttribute(?int $storeId = null): string
    {
        return trim((string) $this->value(self::PATH_SIZES, $storeId));
    }

    public function isHoverImageEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_HOVER_ENABLED, $storeId);
    }

    public function getHoverImageCeiling(?int $storeId = null): int
    {
        return max(0, (int) $this->value(self::PATH_HOVER_MAX_PRODUCTS, $storeId));
    }

    /**
     * @return int Seconds. Zero means "do not let anything cache this".
     */
    public function getStockEndpointTtl(?int $storeId = null): int
    {
        return max(0, (int) $this->value(self::PATH_STOCK_TTL, $storeId));
    }

    public function isGa4Enabled(?int $storeId = null): bool
    {
        return $this->flag(self::PATH_GA4_ENABLED, $storeId);
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function value(string $path, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
