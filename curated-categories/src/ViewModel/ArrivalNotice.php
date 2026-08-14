<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Scr1be\CuratedCategories\Model\Config;
use Scr1be\CuratedCategories\Model\Source\ComingSoon;

/**
 * The dated line the Coming Soon adapter puts on a product page.
 *
 * The rule for showing it is *identical* to the rule the adapter selects on — a restock date that
 * has not passed. That is the point of putting it here rather than in a template condition: a
 * product on the Coming Soon page whose detail page says nothing, or a detail page promising a date
 * for a product the category dropped this morning, are the two ways this feature is normally broken,
 * and both come from the page and the feed asking slightly different questions.
 *
 * ## Placeholders
 *
 * The merchant writes the sentence; the module fills in three values.
 *
 * | Token | Renders |
 * |---|---|
 * | `{date}` | the restock date in the storefront's locale and timezone |
 * | `{days}` | `today`, `in 1 day`, `in 12 days` |
 * | `{weekday}` | the day name, e.g. `Saturday` |
 *
 * Braces rather than `%1`-style positions because the message is edited by someone who is not
 * counting arguments, and an unknown token left in the string is a visible mistake rather than a
 * silently dropped one.
 */
class ArrivalNotice implements ArgumentInterface
{
    private const TOKEN_DATE = '{date}';
    private const TOKEN_DAYS = '{days}';
    private const TOKEN_WEEKDAY = '{weekday}';

    /** ICU pattern for a stand-alone full weekday name. */
    private const WEEKDAY_PATTERN = 'EEEE';

    private const STORED_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly Config $config,
        private readonly TimezoneInterface $localeDate
    ) {
    }

    /**
     * @return string The rendered sentence, or an empty string when there is nothing to say. Callers
     *                treat empty as "render nothing" — there is no separate `shouldShow()` to fall
     *                out of step with this.
     */
    public function getMessage(?Product $product, ?int $storeId = null): string
    {
        $restockDate = $this->getRestockDate($product);

        if ($restockDate === null) {
            return '';
        }

        $template = $this->config->getArrivalMessage(ComingSoon::CODE, $storeId);

        if ($template === '') {
            return '';
        }

        return strtr(
            $template,
            [
                self::TOKEN_DATE => $this->localeDate->formatDate($restockDate, \IntlDateFormatter::MEDIUM),
                self::TOKEN_DAYS => $this->describeDistance($restockDate),
                self::TOKEN_WEEKDAY => $this->localeDate->formatDateTime(
                    $restockDate,
                    \IntlDateFormatter::NONE,
                    \IntlDateFormatter::NONE,
                    null,
                    null,
                    self::WEEKDAY_PATTERN
                ),
            ]
        );
    }

    /**
     * @return \DateTime|null The restock date, or null when it is unset or already past.
     */
    public function getRestockDate(?Product $product): ?\DateTime
    {
        $raw = $product?->getData(ComingSoon::ATTRIBUTE_CODE);

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        // The EAV datetime backend writes `Y-m-d H:i:s` as wall-clock time in the merchant's frame of
        // reference — `Magento\Eav\Model\Entity\Attribute\Backend\Datetime::formatDate()` formats
        // whatever it is given with exactly that pattern and no conversion — and core compares those
        // values against locale-formatted strings. Parsing it in the configured timezone is the
        // reading that agrees with both.
        $restockDate = \DateTime::createFromFormat(
            self::STORED_FORMAT,
            $raw,
            new \DateTimeZone($this->localeDate->getConfigTimezone())
        );

        if ($restockDate === false) {
            return null;
        }

        return $this->startOfDay($restockDate) < $this->today() ? null : $restockDate;
    }

    private function describeDistance(\DateTime $restockDate): string
    {
        $days = (int) $this->today()->diff($this->startOfDay($restockDate))->format('%r%a');

        if ($days <= 0) {
            return (string) __('today');
        }

        return $days === 1 ? (string) __('in 1 day') : (string) __('in %1 days', $days);
    }

    private function today(): \DateTime
    {
        return $this->localeDate->date()->setTime(0, 0, 0);
    }

    private function startOfDay(\DateTime $date): \DateTime
    {
        return (clone $date)->setTime(0, 0, 0);
    }
}
