<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The two numbers that decide how long things live, clamped rather than trusted.
 *
 * Both are seconds and both are admin-editable, which means both can arrive as an empty string, a
 * negative number or "6 hours". A URL TTL of zero is a module that does not work; a TTL of a week
 * is the security property thrown away. Clamping in one place beats defending against it in three.
 */
class Config
{
    private const XML_PATH_URL_TTL = 'scr1be_signed_documents/delivery/url_ttl';
    private const XML_PATH_CACHE_LIFETIME = 'scr1be_signed_documents/cache/lifetime';

    /**
     * Long enough for a phone to change networks between the mutation and the download, short
     * enough that a leaked URL is stale before it is read.
     */
    public const DEFAULT_URL_TTL = 300;
    public const MIN_URL_TTL = 30;
    public const MAX_URL_TTL = 3600;

    /**
     * A day. Rendered PDFs are derived data — the sweep deleting one costs a re-render, not a loss.
     */
    public const DEFAULT_CACHE_LIFETIME = 86400;
    public const MIN_CACHE_LIFETIME = 300;
    public const MAX_CACHE_LIFETIME = 2592000;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getUrlTtl(?int $storeId = null): int
    {
        return $this->clamp(
            $this->scopeConfig->getValue(self::XML_PATH_URL_TTL, ScopeInterface::SCOPE_STORE, $storeId),
            self::MIN_URL_TTL,
            self::MAX_URL_TTL,
            self::DEFAULT_URL_TTL
        );
    }

    /**
     * Read at default scope only: the sweep is a cron job with no store context, and a cache that
     * expired differently per store view would need the store id in the filename to be swept
     * correctly. It is not, so the lifetime is global.
     */
    public function getCacheLifetime(): int
    {
        return $this->clamp(
            $this->scopeConfig->getValue(self::XML_PATH_CACHE_LIFETIME),
            self::MIN_CACHE_LIFETIME,
            self::MAX_CACHE_LIFETIME,
            self::DEFAULT_CACHE_LIFETIME
        );
    }

    private function clamp(mixed $raw, int $min, int $max, int $fallback): int
    {
        if (!is_numeric($raw)) {
            return $fallback;
        }

        $value = (int) $raw;

        return $value < $min || $value > $max ? $fallback : $value;
    }
}
