<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * The short labels a card carries, and nothing else.
 *
 * Every badge here is derived from data the provider has already loaded — the price index columns,
 * the two "new" dates on the product, and the stock item quantity read for the qty rules. That is
 * the whole rule for what belongs in this class: a badge that needs its own query is a badge that
 * costs a query per card, and a popup with six cards would then be six queries deep before it had
 * rendered anything.
 */
class BadgeResolver
{
    public const BADGE_LOW_STOCK = 'low_stock';
    public const BADGE_DISCOUNT = 'discount';
    public const BADGE_NEW = 'new';

    public function __construct(
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * @return array<int, array{code: string, label: string}>
     */
    public function resolve(
        Product $product,
        float $stockQty,
        float $finalPrice,
        float $regularPrice,
        int $lowStockThreshold,
        ?int $storeId = null
    ): array {
        $badges = [];

        // Urgency first, because it is the reason the popup exists: the product came back, and the
        // customer is not the only person who was waiting for it.
        if ($lowStockThreshold > 0 && $stockQty > 0 && $stockQty <= $lowStockThreshold) {
            $badges[] = [
                'code' => self::BADGE_LOW_STOCK,
                'label' => (string)__('Only %1 left', (int)$stockQty),
            ];
        }

        if ($regularPrice > 0.0 && $finalPrice < $regularPrice) {
            $percentage = (int)round(($regularPrice - $finalPrice) / $regularPrice * 100);

            // A rounding artefact — a fifty-cent difference on a five-hundred-euro product — reads
            // as "-0%", which is worse than no badge at all.
            if ($percentage > 0) {
                $badges[] = [
                    'code' => self::BADGE_DISCOUNT,
                    'label' => (string)__('-%1%', $percentage),
                ];
            }
        }

        if ($this->isNew($product, $storeId)) {
            $badges[] = [
                'code' => self::BADGE_NEW,
                'label' => (string)__('New'),
            ];
        }

        return $badges;
    }

    /**
     * `news_from_date` and `news_to_date` are `date` attributes, so both sides of the comparison are
     * a plain `Y-m-d` and the store's timezone decides which day "today" is. An open-ended window —
     * a from-date with no to-date — stays open, which is how the admin form presents it.
     */
    private function isNew(Product $product, ?int $storeId): bool
    {
        $from = $this->toDay($product->getData('news_from_date'));
        $to = $this->toDay($product->getData('news_to_date'));

        if ($from === null && $to === null) {
            return false;
        }

        $today = $this->timezone->scopeDate($storeId)->format('Y-m-d');

        return ($from === null || $from <= $today) && ($to === null || $to >= $today);
    }

    private function toDay(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return substr(trim($value), 0, 10);
    }
}
