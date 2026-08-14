<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model;

/**
 * Stage 3: decide the order of a family, and hand out the positions that end up in
 * `catalog_product_link_attribute_int`.
 *
 * The order comes from the option sort order the merchant set on the variant attribute — the
 * `sort_order` column of `eav_attribute_option` — because that is the only ordering in the system
 * that a person actually chose. Product id order would put a size row in the order the SKUs were
 * created; alphabetical order would read L · M · S · XL · XS.
 *
 * Two properties are worth stating because the rest of the pipeline leans on them:
 *
 * - the result is total and deterministic. Members whose variant value has no option (a free-text
 *   attribute, a value from a deleted option, an empty value) sort after every ranked member, and
 *   every tie anywhere falls back to product id. Two runs over unchanged data write the same
 *   positions, so a re-reconcile produces an empty diff rather than churn;
 * - positions are contiguous from 1. They are recomputed after the capper drops members, so a row
 *   never renders with a gap in it.
 */
class PositionResolver
{
    /**
     * Rank given to members the option ranking does not cover. Kept below PHP_INT_MAX so that adding
     * to it in a comparison cannot overflow.
     */
    private const UNRANKED = PHP_INT_MAX >> 1;

    /**
     * @param array<int, string> $members product id => variant value
     * @param array<string, int> $optionRank variant value => rank, lowest first
     * @return array<int, array{id: int, position: int, variant: string}> ordered, positions from 1
     */
    public function resolve(array $members, array $optionRank): array
    {
        $ordered = [];
        foreach ($members as $productId => $variantValue) {
            $ordered[] = [
                'id' => (int)$productId,
                'rank' => $optionRank[$variantValue] ?? self::UNRANKED,
                'variant' => $variantValue,
            ];
        }

        usort(
            $ordered,
            static fn (array $left, array $right): int => [$left['rank'], $left['id']] <=> [$right['rank'], $right['id']]
        );

        return $this->number($ordered);
    }

    /**
     * Re-number an already ordered list. Used after the capper removes members, so that what is
     * written to the link table is the position inside the row the shopper sees rather than the
     * position inside the family the row was cut from.
     *
     * @param array<int, array{id: int, variant: string, rank?: int}> $ordered
     * @return array<int, array{id: int, position: int, variant: string}>
     */
    public function number(array $ordered): array
    {
        $position = 0;
        $numbered = [];
        foreach ($ordered as $member) {
            $numbered[] = [
                'id' => (int)$member['id'],
                'position' => ++$position,
                'variant' => (string)$member['variant'],
            ];
        }

        return $numbered;
    }
}
