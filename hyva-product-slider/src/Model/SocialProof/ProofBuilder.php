<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\SocialProof;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Scr1be\HyvaProductSlider\Model\Config;
use Scr1be\HyvaProductSlider\Model\ResourceModel\PurchaseIndex;

/**
 * "17 minutes ago, Anna from Austin bought this" — built from real orders, and from as little of them
 * as possible.
 *
 * Three rules shape what this class is allowed to say, and all three are deliberate:
 *
 * 1. **First name and city only.** The index stores nothing else about a buyer, so there is nothing
 *    else to leak. Both halves are individually switchable, and with both off the line degrades to
 *    "someone bought this" rather than disappearing — the useful part of social proof is that it
 *    happened, not who it happened to.
 * 2. **Inside a window.** A purchase older than the configured window produces no line at all.
 *    "Bought 5 weeks ago" is not proof of anything, and a carousel of stale lines reads as a shop
 *    where nothing sells.
 * 3. **Wording on the server.** The sentence is assembled here, in the store's language and
 *    timezone, and the browser receives finished text. See {@see RelativeTime}.
 */
class ProofBuilder
{
    /** Long enough for any real place name, short enough that one line stays one line. */
    private const MAX_CITY_LENGTH = 32;

    public function __construct(
        private readonly PurchaseIndex $purchaseIndex,
        private readonly RelativeTime $relativeTime,
        private readonly TimezoneInterface $localeDate,
        private readonly Config $config
    ) {
    }

    /**
     * @param int[] $productIds
     * @return array<int, Proof> Keyed by product id; products with nothing to say are absent.
     */
    public function build(array $productIds, int $storeId): array
    {
        if (!$this->config->isSocialProofEnabled($storeId) || $productIds === []) {
            return [];
        }

        $now = $this->localeDate->date();
        $since = (clone $now)
            ->modify(sprintf('-%d hours', $this->config->getSocialProofWindowHours($storeId)))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $showName = $this->config->isBuyerNameShown($storeId);
        $showCity = $this->config->isBuyerCityShown($storeId);

        $proofs = [];
        foreach ($this->purchaseIndex->getPurchases($storeId, $productIds, $since) as $productId => $row) {
            $elapsed = $this->elapsedSeconds($row['last_ordered_at'], $now);

            $proofs[$productId] = new Proof(
                $productId,
                $this->compose(
                    $this->relativeTime->format($elapsed),
                    $showName ? $this->firstName($row['buyer_name']) : null,
                    $showCity ? $this->cityName($row['buyer_city']) : null
                ),
                $elapsed,
                $row['purchases']
            );
        }

        return $proofs;
    }

    /**
     * `last_ordered_at` is a UTC timestamp, so it is read as one rather than as a local wall clock.
     * Getting this wrong is invisible in a UTC-configured shop and produces "7 hours ago" for a
     * purchase a minute old everywhere else.
     */
    private function elapsedSeconds(string $lastOrderedAt, \DateTimeInterface $now): int
    {
        try {
            $orderedAt = new \DateTimeImmutable($lastOrderedAt, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return 0;
        }

        return max(0, $now->getTimestamp() - $orderedAt->getTimestamp());
    }

    private function compose(string $elapsed, ?string $name, ?string $city): string
    {
        if ($name !== null && $city !== null) {
            return (string) __('%1 ago, %2 from %3 bought this', $elapsed, $name, $city);
        }

        if ($name !== null) {
            return (string) __('%1 ago, %2 bought this', $elapsed, $name);
        }

        if ($city !== null) {
            return (string) __('%1 ago, someone from %2 bought this', $elapsed, $city);
        }

        return (string) __('%1 ago, someone bought this', $elapsed);
    }

    /**
     * Keeps the first token of the stored first name.
     *
     * `customer_firstname` is free text: shoppers put full names, initials and occasionally an email
     * address in it. Taking the first token bounds the line's length and — the actual point — keeps a
     * surname somebody typed into the wrong box off a public page.
     */
    private function firstName(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        $firstWord = preg_split('/\s+/', $trimmed)[0] ?? '';

        return $firstWord === '' ? null : $firstWord;
    }

    /**
     * A city is kept whole — "New York" must not become "New" — and only bounded in length, because
     * a place name is not a private detail the way a surname is.
     */
    private function cityName(?string $value): ?string
    {
        $trimmed = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';

        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, self::MAX_CITY_LENGTH);
    }
}
