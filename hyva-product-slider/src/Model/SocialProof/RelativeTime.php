<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\SocialProof;

/**
 * "17 minutes", "3 hours", "2 days" — the elapsed half of the social-proof line.
 *
 * Formatted on the server rather than in the browser for one reason: it has to be translatable, and
 * the translation lives in `i18n/en_US.csv` where every other string in this module lives. A client
 * that assembled the sentence would need the store's locale, its plural rules and a copy of the
 * phrases, and would get all three subtly wrong on the second language.
 *
 * Anything under a minute is deliberately not "0 minutes ago". A purchase that just happened reads as
 * "moments ago", which is both truer and better copy.
 */
class RelativeTime
{
    private const SECONDS_PER_MINUTE = 60;
    private const SECONDS_PER_HOUR = 3600;
    private const SECONDS_PER_DAY = 86400;

    public function format(int $elapsedSeconds): string
    {
        // A clock skew between the database and PHP can produce a purchase from the future. Reading
        // it as "moments ago" is the only answer that is never absurd.
        if ($elapsedSeconds < self::SECONDS_PER_MINUTE) {
            return (string) __('moments');
        }

        if ($elapsedSeconds < self::SECONDS_PER_HOUR) {
            $minutes = intdiv($elapsedSeconds, self::SECONDS_PER_MINUTE);

            return $minutes === 1 ? (string) __('a minute') : (string) __('%1 minutes', $minutes);
        }

        if ($elapsedSeconds < self::SECONDS_PER_DAY) {
            $hours = intdiv($elapsedSeconds, self::SECONDS_PER_HOUR);

            return $hours === 1 ? (string) __('an hour') : (string) __('%1 hours', $hours);
        }

        $days = intdiv($elapsedSeconds, self::SECONDS_PER_DAY);

        return $days === 1 ? (string) __('a day') : (string) __('%1 days', $days);
    }
}
