<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed, clamped access to the section. Every getter returns a value the rest of the module can
 * use without re-checking it, because the alternative is a defensive cast at each of the dozen
 * call sites that read the same field.
 */
class Config
{
    private const PATH_ENABLED       = 'scr1be_hyva_media/output/enabled';
    private const PATH_WIDTHS        = 'scr1be_hyva_media/output/widths';
    private const PATH_QUALITY       = 'scr1be_hyva_media/output/quality';
    private const PATH_WEBP_ENABLED  = 'scr1be_hyva_media/webp/enabled';
    private const PATH_WEBP_QUALITY  = 'scr1be_hyva_media/webp/quality';
    private const PATH_MAX_MEGAPIXELS = 'scr1be_hyva_media/limits/max_source_megapixels';
    private const PATH_MAX_ENCODES   = 'scr1be_hyva_media/limits/max_encodes_per_request';

    private const DEFAULT_WIDTHS      = [320, 480, 768, 1024, 1440, 1920];
    private const DEFAULT_QUALITY     = 82;
    private const DEFAULT_WEBP_QUALITY = 78;
    private const DEFAULT_MEGAPIXELS  = 40;
    private const DEFAULT_MAX_ENCODES = 24;

    /**
     * Below 16px a rung is smaller than the placeholder it would replace; above 8192 no browser
     * will ever request it and GD would need ~256 MB to hold the intermediate bitmap.
     */
    private const MIN_WIDTH = 16;
    private const MAX_WIDTH = 8192;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isWebpEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_WEBP_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @return int[] ascending, de-duplicated, in range
     */
    public function getWidths(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::PATH_WIDTHS, ScopeInterface::SCOPE_STORE, $storeId);
        $widths = [];
        foreach (explode(',', $raw) as $candidate) {
            $width = (int) trim($candidate);
            if ($width >= self::MIN_WIDTH && $width <= self::MAX_WIDTH) {
                $widths[$width] = $width;
            }
        }

        if ($widths === []) {
            return self::DEFAULT_WIDTHS;
        }

        $widths = array_values($widths);
        sort($widths);

        return $widths;
    }

    public function getQuality(?int $storeId = null): int
    {
        return $this->clampQuality(
            $this->scopeConfig->getValue(self::PATH_QUALITY, ScopeInterface::SCOPE_STORE, $storeId),
            self::DEFAULT_QUALITY
        );
    }

    public function getWebpQuality(?int $storeId = null): int
    {
        return $this->clampQuality(
            $this->scopeConfig->getValue(self::PATH_WEBP_QUALITY, ScopeInterface::SCOPE_STORE, $storeId),
            self::DEFAULT_WEBP_QUALITY
        );
    }

    public function getMaxSourceMegapixels(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(self::PATH_MAX_MEGAPIXELS, ScopeInterface::SCOPE_STORE, $storeId);

        return $value > 0 ? $value : self::DEFAULT_MEGAPIXELS;
    }

    public function getMaxEncodesPerRequest(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(self::PATH_MAX_ENCODES, ScopeInterface::SCOPE_STORE, $storeId);

        return $value > 0 ? $value : self::DEFAULT_MAX_ENCODES;
    }

    private function clampQuality(mixed $raw, int $fallback): int
    {
        // An empty field is not a request for quality 0 — it is a request for the default. Only a
        // value the admin actually typed gets clamped into range.
        if ($raw === null || $raw === '') {
            return $fallback;
        }

        return max(1, min(100, (int) $raw));
    }
}
