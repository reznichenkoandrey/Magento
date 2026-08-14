<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\Data\Collection as DataCollection;
use Scr1be\HyvaMegaMenu\Model\Icon\Icon;
use Scr1be\HyvaMegaMenu\Model\Icon\IconResolver;

/**
 * Builds the whole menu — every level, every icon — with one category query.
 *
 * Three decisions are load-bearing here.
 *
 * **The icon attributes are selected in the tree query.** They are ordinary category attributes,
 * so `addAttributeToSelect()` folds them into the joins the collection was going to make for the
 * name anyway. The alternative — walking the tree and then asking a repository for each
 * category's extra fields — is the shape this module had in its first version, and it costs one
 * round trip per menu entry on a page that is otherwise a single query.
 *
 * **The EAV collection is used, not the flat one.** Core's `StateDependentCollectionFactory`
 * hands back `Category\Flat\Collection` when the category flat index is on, and that class's
 * `addAttributeToSelect()` is not the EAV one: it adds *columns of the flat table* to the select.
 * An attribute with no column there does not degrade — it produces an SQL error. The EAV
 * collection answers correctly whether or not the flat index exists, which is worth more here
 * than the flat table's read speed on a query that runs once per cached page.
 *
 * **The url rewrite is joined rather than looked up.** `addUrlRewriteToResult()` puts
 * `request_path` on every row, and `Category::getUrl()` uses it when it is there instead of
 * asking the url finder for one rewrite at a time.
 */
class MenuTreeBuilder
{
    /**
     * Root categories are the level-1 nodes of the tree, so a root category's path is always
     * `1/<id>` and its children start at level 2. Core states the same thing from the other
     * direction in `Collection::addRootLevelFilter()`, which defines a root as `path != '1'` and
     * `level <= 1`.
     */
    private const ROOT_CATEGORY_LEVEL = 1;

    private const LEVELS_WITHOUT_THIRD = 2;
    private const LEVELS_WITH_THIRD = 3;

    /**
     * Keys are prefixed rather than being bare ids so they are usable verbatim as a JSON object
     * key and as the value of a `data-` attribute the component matches on, with no chance of a
     * numeric key being reordered by a JSON parser that treats it as an array index.
     */
    private const NODE_KEY_PREFIX = 'c';

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly IconResolver $iconResolver
    ) {
    }

    public function build(int $rootCategoryId, int $storeId, bool $withThirdLevel): MenuTree
    {
        $depth = $withThirdLevel ? self::LEVELS_WITH_THIRD : self::LEVELS_WITHOUT_THIRD;

        $nodes = [];
        $categoryIds = [];
        $spriteKeys = [];

        foreach ($this->loadCategories($rootCategoryId, $storeId, $depth) as $category) {
            $parentId = (int) $category->getParentId();
            $isTopLevel = $parentId === $rootCategoryId;

            // A category whose parent did not survive the is_active / include_in_menu filters is
            // an orphan, and so is everything below it. Rows arrive level by level, so the parent
            // has already been accepted or rejected by the time its children are read, and
            // dropping the node here drops its whole branch without a second pass.
            if (!$isTopLevel && !isset($nodes[$parentId])) {
                continue;
            }

            $icon = $this->iconResolver->resolve($category);

            if ($icon->type === Icon::TYPE_SPRITE) {
                $spriteKeys[$icon->value] = true;
            }

            $categoryId = (int) $category->getId();
            $categoryIds[] = $categoryId;

            $nodes[$categoryId] = [
                'key' => self::NODE_KEY_PREFIX . $categoryId,
                'id' => $categoryId,
                'name' => (string) $category->getName(),
                'url' => (string) $category->getUrl(),
                'icon' => $icon,
                'parent_id' => $isTopLevel ? 0 : $parentId,
                'children' => [],
            ];
        }

        return $this->assemble($nodes, $categoryIds, array_keys($spriteKeys));
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param int[] $categoryIds
     * @param string[] $spriteKeys
     */
    private function assemble(array $nodes, array $categoryIds, array $spriteKeys): MenuTree
    {
        // Children are attached back to front so that a parent is complete before it is copied
        // into its own parent. Arrays are values in PHP, so attaching in reverse level order is
        // what makes a single pass enough — going forwards would copy half-built branches.
        foreach (array_reverse($nodes, true) as $id => $node) {
            $parentId = $node['parent_id'];

            if ($parentId !== 0 && isset($nodes[$parentId])) {
                array_unshift($nodes[$parentId]['children'], $nodes[$id]);
                unset($nodes[$id]);
            }
        }

        $items = [];
        $island = [];

        foreach ($nodes as $node) {
            $items[] = $this->splitThirdLevel($node, $island);
        }

        return new MenuTree($items, $island, $categoryIds, $spriteKeys);
    }

    /**
     * Moves every third-level branch out of the render tree and into the island payload.
     *
     * The split happens here rather than in the template because it is the same decision as the
     * SEO one: level 1 and level 2 are anchors in the HTML exactly once, level 3 is data that
     * becomes anchors only when a shopper opens the branch it belongs to.
     *
     * @param array<string, mixed> $node
     * @param array<string, array<int, array{n: string, u: string, i: array{t: string, v: string}|null}>> $island
     * @return array<string, mixed>
     */
    private function splitThirdLevel(array $node, array &$island): array
    {
        foreach ($node['children'] as $index => $child) {
            if ($child['children'] !== []) {
                $island[$child['key']] = array_map(
                    static fn (array $grandChild): array => [
                        'n' => $grandChild['name'],
                        'u' => $grandChild['url'],
                        'i' => $grandChild['icon']->toIslandArray(),
                    ],
                    $child['children']
                );
            }

            $node['children'][$index]['has_children'] = $child['children'] !== [];
            $node['children'][$index]['children'] = [];
        }

        return $node;
    }

    /**
     * @return iterable<Category>
     */
    private function loadCategories(int $rootCategoryId, int $storeId, int $depth): iterable
    {
        /** @var Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect(array_merge(['name'], IconResolver::ATTRIBUTES));
        $collection->addFieldToFilter(
            'path',
            ['like' => Category::TREE_ROOT_ID . '/' . $rootCategoryId . '/%']
        );
        $collection->addAttributeToFilter('include_in_menu', 1);
        $collection->addIsActiveFilter();
        $collection->addLevelFilter(self::ROOT_CATEGORY_LEVEL + $depth);
        $collection->addUrlRewriteToResult();
        // Level first is not cosmetic: the assembler relies on a parent having been seen before
        // its children so that an orphaned branch can be dropped in one pass.
        $collection->addOrder('level', DataCollection::SORT_ORDER_ASC);
        $collection->addOrder('position', DataCollection::SORT_ORDER_ASC);
        $collection->addOrder('parent_id', DataCollection::SORT_ORDER_ASC);
        $collection->addOrder('entity_id', DataCollection::SORT_ORDER_ASC);

        return $collection;
    }
}
