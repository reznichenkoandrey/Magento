<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\Model;

use Magento\Framework\Locale\ResolverInterface;
use NumberFormatter;

/**
 * Renders a tier-price quantity for display.
 *
 * `catalog_product_entity_tier_price.qty` is a DECIMAL(12,4), so a raw cast prints
 * "5" as "5" but "1.5" as "1.5" only by luck and "1000" as "1000" in every locale — which is
 * wrong in most of them. Going through NumberFormatter costs one object per locale and gets
 * grouping separators and decimal marks right for free.
 */
class QtyFormatter
{
    /**
     * Matches the scale of the tier-price column; anything beyond it is storage noise.
     */
    private const MAX_FRACTION_DIGITS = 4;

    private const MIN_FRACTION_DIGITS = 0;

    /**
     * @var NumberFormatter[] keyed by locale code
     */
    private array $formatters = [];

    public function __construct(
        private readonly ResolverInterface $localeResolver
    ) {
    }

    public function format(float $qty): string
    {
        return (string) $this->getFormatter()->format($qty);
    }

    private function getFormatter(): NumberFormatter
    {
        $locale = $this->localeResolver->getLocale();

        if (!isset($this->formatters[$locale])) {
            $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, self::MIN_FRACTION_DIGITS);
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, self::MAX_FRACTION_DIGITS);
            $this->formatters[$locale] = $formatter;
        }

        return $this->formatters[$locale];
    }
}
