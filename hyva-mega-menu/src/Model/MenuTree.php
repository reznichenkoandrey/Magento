<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Model;

use Magento\Catalog\Model\Category;
use Scr1be\HyvaMegaMenu\Model\Icon\Icon;

/**
 * One built menu: the two levels that get server-rendered, the third level's payload, the cache
 * identities the page has to carry, and the sprite keys the page actually used.
 *
 * All four are derived from the same walk, which is why they live together. Recomputing the
 * identity list from the rendered markup, or the sprite keys from the identity list, would be two
 * more places for the answers to drift apart from each other.
 *
 * @phpstan-type MenuNode array{key: string, id: int, name: string, url: string, icon: Icon, children: array<int, mixed>}
 */
final class MenuTree
{
    /**
     * Above this many categories the page carries a single `cat_c` tag instead of one tag per
     * category. The cap is about the `X-Magento-Tags` header, which every cacheable response
     * carries and some reverse proxies bound; a menu with several hundred entries is already a
     * merchandising problem, and it should not also be a header-size one.
     *
     * The trade-off is stated rather than hidden: above the cap a category *rename* no longer
     * invalidates the page on its own, because core only emits the bare `cat_c` tag when a
     * category is created, deleted, or has its menu membership changed. See
     * `Magento\Catalog\Model\Category::getIdentities()`.
     */
    public const MAX_CATEGORY_IDENTITIES = 200;

    /**
     * @param array<int, array<string, mixed>> $items level-1 nodes, each with its level-2 children
     * @param array<string, array<int, array{n: string, u: string, i: array{t: string, v: string}|null}>> $island
     * @param int[] $categoryIds every category that ended up in the menu, in walk order
     * @param string[] $spriteKeys the sprite symbols the menu referenced, deduplicated
     */
    public function __construct(
        public readonly array $items,
        public readonly array $island,
        private readonly array $categoryIds,
        public readonly array $spriteKeys
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], [], []);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Cache tags for the full-page cache and for this block's own block_html entry.
     *
     * The bare `cat_c` tag is in the list unconditionally, and it is not redundant next to the
     * per-category tags: a category that is *added* to the menu has no tag in the list yet,
     * because it did not exist when the page was rendered. Core emits the bare tag exactly in
     * that case — on create, on delete, and when `include_in_menu` changes — which is precisely
     * the set of changes that alter who is in the menu without changing anyone who already was.
     *
     * @return string[]
     */
    public function getIdentities(): array
    {
        if ($this->categoryIds === []) {
            return [];
        }

        if (count($this->categoryIds) > self::MAX_CATEGORY_IDENTITIES) {
            return [Category::CACHE_TAG];
        }

        $identities = [Category::CACHE_TAG];

        foreach ($this->categoryIds as $categoryId) {
            $identities[] = Category::CACHE_TAG . '_' . $categoryId;
        }

        return $identities;
    }
}
