<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model;

/**
 * Stage 4: collapse duplicate variants, then decide which members of a family each member links to.
 *
 * The cap is the interesting half. The obvious reading of "at most twelve" is "keep the first twelve
 * members and link them to each other", but on a coarse family key — grouping by material, say —
 * that leaves the other four hundred members of the family with no links at all, and gives the
 * twelve that survived an identical row. The reading used here is per source product: every member
 * keeps a row, and the row holds the twelve members nearest to it in family order.
 *
 * Nearest, not first, because family order is meaningful. On a size family the neighbours of M are S
 * and L, which is what a shopper reaching for a different size wants; on a colour family the
 * neighbours are whatever the merchant put next to that colour in the option list, which is at least
 * their own decision. Ties — a member two places below and two places above — resolve towards the
 * lower position, so the row leans towards the start of the family rather than flapping.
 *
 * What is written as the link position is the *linked* member's position in the family, not its rank
 * inside the window. That is what makes every row on every product page render in the same order.
 */
class FamilyCapper
{
    public function __construct(
        private readonly PositionResolver $positionResolver
    ) {
    }

    /**
     * Keep one member per variant value.
     *
     * Empty variant values are never collapsed: an empty value is "unknown", not "the same as the
     * other unknown", and folding them together would delete the rest of the family whenever the
     * attribute is unset on most of the catalogue.
     *
     * @param array<int, array{id: int, position: int, variant: string}> $ordered
     * @return array<int, array{id: int, position: int, variant: string}> renumbered from 1
     */
    public function collapseDuplicateVariants(array $ordered): array
    {
        $seen = [];
        $kept = [];
        foreach ($ordered as $member) {
            if ($member['variant'] !== '') {
                if (isset($seen[$member['variant']])) {
                    continue;
                }
                $seen[$member['variant']] = true;
            }
            $kept[] = $member;
        }

        return $this->positionResolver->number($kept);
    }

    /**
     * @param array<int, array{id: int, position: int, variant: string}> $ordered
     * @param int $maxMembers how many links one product may carry inside this family
     * @return array<int, array<int, int>> product id => linked product id => position
     */
    public function buildLinks(array $ordered, int $maxMembers): array
    {
        if ($maxMembers < 1 || count($ordered) < 2) {
            return [];
        }

        $links = [];
        foreach ($ordered as $source) {
            $neighbours = [];
            foreach ($ordered as $candidate) {
                if ($candidate['id'] === $source['id']) {
                    continue;
                }
                $neighbours[] = [
                    'distance' => abs($candidate['position'] - $source['position']),
                    'position' => $candidate['position'],
                    'id' => $candidate['id'],
                ];
            }

            usort(
                $neighbours,
                static fn (array $left, array $right): int
                    => [$left['distance'], $left['position'], $left['id']]
                    <=> [$right['distance'], $right['position'], $right['id']]
            );

            $row = [];
            foreach (array_slice($neighbours, 0, $maxMembers) as $neighbour) {
                $row[$neighbour['id']] = $neighbour['position'];
            }

            // Sorted so that the desired state has a stable shape whatever order the window found
            // the neighbours in — the diff against the database compares values, but a stable shape
            // makes the dry-run output readable and the unit tests honest.
            ksort($row);
            $links[$source['id']] = $row;
        }

        return $links;
    }
}
