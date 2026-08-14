<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Test\Unit\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMegaMenu\Model\Icon\Icon;
use Scr1be\HyvaMegaMenu\Model\Icon\IconResolver;
use Scr1be\HyvaMegaMenu\Model\MenuTreeBuilder;

/**
 * The builder is the one place where a flat result set becomes a tree, and every rule it applies on
 * the way is a rule that is invisible in the rendered markup when it goes wrong: an orphan that
 * survived shows up as a top-level entry nobody added, a third level left in the render tree shows
 * up as a page that is twice the size it should be.
 */
class MenuTreeBuilderTest extends TestCase
{
    private const ROOT_ID = 2;
    private const STORE_ID = 1;

    private CollectionFactory&MockObject $collectionFactory;
    private Collection&MockObject $collection;
    private IconResolver&MockObject $iconResolver;
    private MenuTreeBuilder $builder;

    /**
     * @var array<int, Icon> category id => the icon the resolver reports for it
     */
    private array $icons = [];

    protected function setUp(): void
    {
        $this->collection = $this->createMock(Collection::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $this->iconResolver = $this->createMock(IconResolver::class);
        $this->iconResolver->method('resolve')->willReturnCallback(
            fn (Category $category): Icon => $this->icons[(int) $category->getId()] ?? Icon::none()
        );

        $this->builder = new MenuTreeBuilder($this->collectionFactory, $this->iconResolver);
    }

    /**
     * Rows arrive level by level, which is what the assembler depends on — a child is only ever
     * read after its parent has been accepted or rejected.
     *
     * @param array<int, array{0: int, 1: int, 2: string}> $rows id, parent id, name
     */
    private function rows(array $rows): void
    {
        $categories = [];

        foreach ($rows as [$id, $parentId, $name]) {
            $category = $this->createMock(Category::class);
            $category->method('getId')->willReturn($id);
            $category->method('getParentId')->willReturn($parentId);
            $category->method('getName')->willReturn($name);
            $category->method('getUrl')->willReturn('https://example.test/' . strtolower($name) . '.html');
            $categories[] = $category;
        }

        $this->collection->method('getIterator')->willReturn(new \ArrayIterator($categories));
    }

    /**
     * A catalogue with two top-level entries, one of which has two children and a third level under
     * the first of them — plus a second-level branch whose parent is not in the result set at all.
     */
    private function catalogue(): void
    {
        $this->rows([
            [3, self::ROOT_ID, 'Gear'],
            [4, self::ROOT_ID, 'Sale'],
            [11, 3, 'Bags'],
            [12, 3, 'Fitness'],
            [33, 99, 'Orphan'],
            [21, 11, 'Duffle'],
            [22, 11, 'Backpack'],
            [44, 33, 'OrphanChild'],
        ]);
    }

    public function testTheWholeMenuComesFromOneCategoryQuery(): void
    {
        $this->collectionFactory->expects($this->once())->method('create');
        $this->catalogue();

        $this->builder->build(self::ROOT_ID, self::STORE_ID, true);
    }

    /**
     * The icon attributes are selected in the tree query rather than fetched per category. Losing
     * this is not a broken menu, it is a menu that costs one round trip per entry — the exact
     * regression a later refactor would not notice.
     */
    public function testTheIconAttributesAreSelectedAlongsideTheName(): void
    {
        $this->collection->expects($this->once())
            ->method('addAttributeToSelect')
            ->with(array_merge(['name'], IconResolver::ATTRIBUTES));
        $this->catalogue();

        $this->builder->build(self::ROOT_ID, self::STORE_ID, true);
    }

    /**
     * Root categories are level 1, so a two-level menu reaches level 3 and a three-level one
     * reaches level 4. Switching the third level off has to stop it being *queried*, not merely
     * stop it being rendered.
     */
    public function testTheThirdLevelSettingChangesTheDepthOfTheQuery(): void
    {
        $this->collection->expects($this->once())->method('addLevelFilter')->with(3);
        $this->catalogue();

        $this->builder->build(self::ROOT_ID, self::STORE_ID, false);
    }

    public function testTheThirdLevelIsQueriedWhenItIsEnabled(): void
    {
        $this->collection->expects($this->once())->method('addLevelFilter')->with(4);
        $this->catalogue();

        $this->builder->build(self::ROOT_ID, self::STORE_ID, true);
    }

    public function testChildrenAreNestedUnderTheTopLevelEntryTheyBelongTo(): void
    {
        $this->catalogue();

        $items = $this->builder->build(self::ROOT_ID, self::STORE_ID, true)->items;

        $this->assertSame(['c3', 'c4'], array_column($items, 'key'));
        $this->assertSame(['c11', 'c12'], array_column($items[0]['children'], 'key'));
        $this->assertSame([], $items[1]['children']);
    }

    /**
     * The split is the SEO decision made in data rather than in the template: levels 1 and 2 are
     * anchors in the HTML, level 3 is a payload that becomes anchors on demand.
     */
    public function testTheThirdLevelLeavesTheRenderTreeAndTravelsInTheIsland(): void
    {
        $this->icons = [21 => Icon::color('#abc')];
        $this->catalogue();

        $tree = $this->builder->build(self::ROOT_ID, self::STORE_ID, true);
        $bags = $tree->items[0]['children'][0];

        $this->assertTrue($bags['has_children']);
        $this->assertSame([], $bags['children']);
        $this->assertFalse($tree->items[0]['children'][1]['has_children']);
        $this->assertSame(
            [
                ['n' => 'Duffle', 'u' => 'https://example.test/duffle.html', 'i' => ['t' => 'color', 'v' => '#abc']],
                ['n' => 'Backpack', 'u' => 'https://example.test/backpack.html', 'i' => null],
            ],
            $tree->island['c11']
        );
    }

    public function testABranchWithNoThirdLevelContributesNothingToTheIsland(): void
    {
        $this->catalogue();

        $this->assertSame(['c11'], array_keys($this->builder->build(self::ROOT_ID, self::STORE_ID, true)->island));
    }

    /**
     * A category whose parent failed the is_active or include_in_menu filters is an orphan, and so
     * is everything below it. Promoting one to the top level — which is what a builder that keyed
     * only on "is my parent the root" would do — publishes a category the merchant switched off.
     */
    public function testAnOrphanedBranchIsDroppedWholesale(): void
    {
        $this->catalogue();

        $tree = $this->builder->build(self::ROOT_ID, self::STORE_ID, true);

        $this->assertSame(['c3', 'c4'], array_column($tree->items, 'key'));
        $this->assertArrayNotHasKey('c33', $tree->island);
        $this->assertSame(
            ['cat_c', 'cat_c_3', 'cat_c_4', 'cat_c_11', 'cat_c_12', 'cat_c_21', 'cat_c_22'],
            $tree->getIdentities()
        );
    }

    /**
     * Only the symbols the menu actually referenced are collected, and each of them once — the
     * sprite ships inside every cached page, so a duplicate is a duplicate on every request.
     */
    public function testSpriteKeysAreCollectedOnceEach(): void
    {
        $this->icons = [
            3 => Icon::sprite('tag'),
            4 => Icon::sprite('bag'),
            11 => Icon::sprite('tag'),
            12 => Icon::image('https://example.test/media/icon.png'),
        ];
        $this->catalogue();

        $this->assertSame(['tag', 'bag'], $this->builder->build(self::ROOT_ID, self::STORE_ID, true)->spriteKeys);
    }

    public function testACatalogueWithNothingInTheMenuBuildsAnEmptyTree(): void
    {
        $this->rows([]);

        $tree = $this->builder->build(self::ROOT_ID, self::STORE_ID, true);

        $this->assertTrue($tree->isEmpty());
        $this->assertSame([], $tree->island);
        $this->assertSame([], $tree->getIdentities());
    }
}
