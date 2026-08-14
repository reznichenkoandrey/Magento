<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model;

/**
 * The diff. Given what the catalogue says a link type should contain and what the table currently
 * contains, produce the three statements' worth of work and nothing else.
 *
 * This class is the reason the module does not wipe and rebuild. Wipe-and-rebuild is one line
 * shorter and wrong for three separate reasons: it burns `catalog_product_link.link_id` — an
 * unsigned int auto-increment — once per product per run; it puts every product in the catalogue
 * into the page-cache invalidation set every night whether or not anything about it changed; and it
 * leaves a window, however short, in which a live product page renders with no row on it.
 *
 * A re-ranked link is an UPDATE of one integer in `catalog_product_link_attribute_int`, not a
 * delete-and-insert of the link itself, for the same reason: the link's identity did not change.
 */
class LinkPlanner
{
    /**
     * @param array<int, array<int, int>> $desired product id => linked product id => position
     * @param array<int, array<int, array{link_id: int, position: int}>> $current the same shape,
     *        as read from `catalog_product_link` joined to its position attribute
     */
    public function plan(array $desired, array $current): LinkPlan
    {
        $inserts = [];
        $updates = [];
        $deletes = [];
        $affected = [];
        $unchanged = 0;

        foreach ($desired as $productId => $links) {
            $productId = (int)$productId;
            $existing = $current[$productId] ?? [];

            foreach ($links as $linkedProductId => $position) {
                $linkedProductId = (int)$linkedProductId;
                $position = (int)$position;

                if (!isset($existing[$linkedProductId])) {
                    $inserts[] = [
                        'product_id' => $productId,
                        'linked_product_id' => $linkedProductId,
                        'position' => $position,
                    ];
                    $affected[$productId] = true;
                    continue;
                }

                if ($existing[$linkedProductId]['position'] !== $position) {
                    $updates[] = [
                        'link_id' => (int)$existing[$linkedProductId]['link_id'],
                        'position' => $position,
                    ];
                    $affected[$productId] = true;
                    continue;
                }

                $unchanged++;
            }
        }

        foreach ($current as $productId => $links) {
            $productId = (int)$productId;
            $wanted = $desired[$productId] ?? [];

            foreach ($links as $linkedProductId => $row) {
                if (!isset($wanted[(int)$linkedProductId])) {
                    $deletes[] = (int)$row['link_id'];
                    $affected[$productId] = true;
                }
            }
        }

        // Sorted so that two runs over the same data produce byte-identical plans. The writer does
        // not care, but a diffable dry run and a reproducible test do.
        $affectedProductIds = array_keys($affected);
        sort($affectedProductIds);
        sort($deletes);

        return new LinkPlan($inserts, $updates, $deletes, $affectedProductIds, $unchanged);
    }
}
