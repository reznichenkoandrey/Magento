<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model;

/**
 * Stage 2 of the pipeline: turn a flat scan into families.
 *
 * The one thing this stage has an opinion about is multi-valued group attributes. A multiselect
 * stores its option ids as a comma-separated list in a single `text` row, so a bag whose
 * `style_bags` is "Backpack,Messenger" belongs to two families at once — and refusing to split it
 * would silently make every multiselect attribute unusable as a family key. Splitting is therefore
 * unconditional and harmless for single-valued attributes, whose values never contain a comma
 * (option ids and integers cannot, and a text attribute that does would have been a poor family key).
 */
class Grouper
{
    /**
     * @param iterable<array{entity_id: int|string, group_value: string|null, variant_value: string|null}> $rows
     * @return array<int|string, array<int, string>> family key => product id => variant value
     */
    public function group(iterable $rows): array
    {
        $families = [];

        foreach ($rows as $row) {
            // `entity_id` is always in the scanner's SELECT, so it is always in the row; only its
            // PHP type varies, which the cast settles.
            $productId = (int)$row['entity_id'];
            if ($productId <= 0) {
                continue;
            }

            $variantValue = trim((string)($row['variant_value'] ?? ''));

            foreach ($this->splitKeys((string)($row['group_value'] ?? '')) as $key) {
                $families[$key][$productId] = $variantValue;
            }
        }

        return $families;
    }

    /**
     * Drop families that cannot produce a link. A family of one is the common case on a real
     * catalogue — most products are the only thing with their exact attribute value — and carrying
     * them through the remaining stages costs more than the filter.
     *
     * @param array<int|string, array<int, string>> $families
     * @return array<int|string, array<int, string>>
     */
    public function dropSingletons(array $families): array
    {
        return array_filter($families, static fn (array $members): bool => count($members) > 1);
    }

    /**
     * @return string[]
     */
    private function splitKeys(string $rawValue): array
    {
        $keys = [];
        foreach (explode(',', $rawValue) as $part) {
            $key = trim($part);
            if ($key !== '') {
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }
}
