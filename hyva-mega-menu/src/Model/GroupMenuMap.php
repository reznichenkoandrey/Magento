<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Model;

/**
 * Parses the admin's `customerGroupId:rootCategoryId` list into something the resolver can ask.
 *
 * The field is free text typed by a human, so the parser's job is to be unsurprising rather than
 * strict: it accepts commas, semicolons and newlines as separators, tolerates spaces around the
 * colon, and drops anything it cannot read instead of throwing. A malformed line must not be able
 * to take the header menu off the page.
 *
 * Group 0 is a real customer group in Magento — NOT LOGGED IN — so it is accepted as a key. That
 * is the reason the parser insists on digits rather than casting: a lenient `(int)` on a typo
 * produces group 0 out of thin air, which would quietly re-point the menu for every guest.
 */
class GroupMenuMap
{
    private const SEPARATORS = "\r\n,;";
    private const PAIR_DELIMITER = ':';

    /**
     * @return array<int, int> customer group id => root category id
     */
    public function parse(string $raw): array
    {
        $map = [];

        foreach ($this->splitEntries($raw) as $entry) {
            $pair = $this->parseEntry($entry);

            if ($pair !== null) {
                [$groupId, $rootCategoryId] = $pair;
                $map[$groupId] = $rootCategoryId;
            }
        }

        return $map;
    }

    public function isEmpty(string $raw): bool
    {
        return $this->parse($raw) === [];
    }

    /**
     * @return string[]
     */
    private function splitEntries(string $raw): array
    {
        $entries = preg_split('/[' . preg_quote(self::SEPARATORS, '/') . ']+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $entries), static fn (string $entry): bool => $entry !== ''));
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function parseEntry(string $entry): ?array
    {
        $parts = explode(self::PAIR_DELIMITER, $entry);

        if (count($parts) !== 2) {
            return null;
        }

        $groupId = trim($parts[0]);
        $rootCategoryId = trim($parts[1]);

        // Root category 0 is rejected on purpose: Category::ROOT_CATEGORY_ID is the sentinel for
        // "this store has no root category", so mapping a group onto it is never what was meant.
        if (!ctype_digit($groupId) || !ctype_digit($rootCategoryId) || (int) $rootCategoryId === 0) {
            return null;
        }

        return [(int) $groupId, (int) $rootCategoryId];
    }
}
