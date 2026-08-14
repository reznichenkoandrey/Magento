<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Test\Unit\Model;

use Magento\Catalog\Model\Category;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMegaMenu\Model\MenuTree;

class MenuTreeTest extends TestCase
{
    public function testAnEmptyMenuCarriesNoTags(): void
    {
        $this->assertSame([], MenuTree::empty()->getIdentities());
        $this->assertTrue(MenuTree::empty()->isEmpty());
    }

    /**
     * The bare `cat_c` tag is not redundant next to the per-category ones. A category that is
     * *added* to the menu has no tag in the list, because it did not exist when the page was
     * rendered — and core emits the bare tag exactly on create, delete and a change of
     * `include_in_menu`.
     */
    public function testEveryRenderedCategoryIsTaggedAlongsideTheBareCategoryTag(): void
    {
        $tree = new MenuTree([], [], [3, 11, 12], []);

        $this->assertSame(
            [Category::CACHE_TAG, 'cat_c_3', 'cat_c_11', 'cat_c_12'],
            $tree->getIdentities()
        );
    }

    /**
     * Above the cap the page carries one tag instead of hundreds, because `X-Magento-Tags` goes
     * out on every cacheable response and some proxies bound its size. The cost is stated in the
     * README rather than hidden: a rename above the cap no longer invalidates on its own.
     */
    public function testAboveTheCapOnlyTheBareTagIsCarried(): void
    {
        $categoryIds = range(1, MenuTree::MAX_CATEGORY_IDENTITIES + 1);

        $this->assertSame([Category::CACHE_TAG], (new MenuTree([], [], $categoryIds, []))->getIdentities());
    }

    public function testTheCapItselfIsStillFullyTagged(): void
    {
        $categoryIds = range(1, MenuTree::MAX_CATEGORY_IDENTITIES);

        $this->assertCount(
            MenuTree::MAX_CATEGORY_IDENTITIES + 1,
            (new MenuTree([], [], $categoryIds, []))->getIdentities()
        );
    }
}
