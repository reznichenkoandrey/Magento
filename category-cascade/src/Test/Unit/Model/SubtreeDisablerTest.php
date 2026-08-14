<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Test\Unit\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CategoryCascade\Model\ResourceModel\OverrideSweeper;
use Scr1be\CategoryCascade\Model\SubtreeDisabler;
use Scr1be\CategoryCascade\Model\SubtreeLocator;

class SubtreeDisablerTest extends TestCase
{
    private SubtreeLocator&MockObject $locator;
    private CategoryResource&MockObject $categoryResource;
    private OverrideSweeper&MockObject $sweeper;
    private AdapterInterface&MockObject $connection;
    private SubtreeDisabler $disabler;

    protected function setUp(): void
    {
        $this->locator = $this->createMock(SubtreeLocator::class);
        $this->categoryResource = $this->createMock(CategoryResource::class);
        $this->sweeper = $this->createMock(OverrideSweeper::class);
        $this->connection = $this->createMock(AdapterInterface::class);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);

        $this->disabler = new SubtreeDisabler(
            $this->locator,
            $this->categoryResource,
            $this->sweeper,
            $resourceConnection
        );
    }

    public function testALeafCategoryOpensNoTransactionAtAll(): void
    {
        $this->locator->method('loadDescendants')->willReturn([]);
        $this->connection->expects($this->never())->method('beginTransaction');
        $this->sweeper->expects($this->never())->method('clearEnabledOverrides');

        $result = $this->disabler->disableSubtree($this->parent());

        $this->assertSame([], $result->getSubtreeIds());
        $this->assertFalse($result->hasChanges());
    }

    public function testWritesOnlyTheChildrenThatAreStillEnabled(): void
    {
        $enabled = $this->child(31, true);
        $alreadyOff = $this->child(32, false);
        $this->locator->method('loadDescendants')->willReturn([$enabled, $alreadyOff]);

        $enabled->expects($this->once())->method('setStoreId')->with(0);
        $enabled->expects($this->once())->method('setData')->with('is_active', 0);
        $alreadyOff->expects($this->never())->method('setData');

        $this->categoryResource->expects($this->once())
            ->method('saveAttribute')
            ->with($enabled, 'is_active');

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');
        $this->connection->expects($this->never())->method('rollBack');

        $result = $this->disabler->disableSubtree($this->parent());

        $this->assertSame([31, 32], $result->getSubtreeIds());
        $this->assertSame([31], $result->getDisabledIds());
    }

    /**
     * The sweep covers every descendant, not just the ones that were written: a child already
     * disabled by default can still carry an enabling store override, and that child needs no
     * save and does need the sweep.
     */
    public function testSweepsTheWholeSubtreeWhenSavedInTheDefaultScope(): void
    {
        $this->locator->method('loadDescendants')->willReturn([
            $this->child(31, true),
            $this->child(32, false),
        ]);

        $this->sweeper->expects($this->once())
            ->method('clearEnabledOverrides')
            ->with([31, 32])
            ->willReturn(2);

        $result = $this->disabler->disableSubtree($this->parent());

        $this->assertSame(2, $result->getClearedOverrideRows());
        $this->assertTrue($result->hasChanges());
    }

    /**
     * A cascade inside a store view writes that store's rows directly. The other store views are
     * separate decisions and stay untouched.
     */
    public function testDoesNotSweepWhenTheCascadeRunsInsideAStoreView(): void
    {
        $this->locator->method('loadDescendants')->willReturn([$this->child(31, true)]);
        $this->sweeper->expects($this->never())->method('clearEnabledOverrides');

        $result = $this->disabler->disableSubtree($this->parent(2));

        $this->assertSame(0, $result->getClearedOverrideRows());
    }

    public function testAFailedWriteRollsBackTheWholeSubtreeAndRethrows(): void
    {
        $this->locator->method('loadDescendants')->willReturn([
            $this->child(31, true),
            $this->child(32, true),
        ]);

        $this->categoryResource->method('saveAttribute')
            ->willReturnCallback(static function (Category $child): void {
                if ((int) $child->getId() === 32) {
                    throw new \RuntimeException('deadlock');
                }
            });

        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('deadlock');

        $this->disabler->disableSubtree($this->parent());
    }

    private function parent(int $storeId = 0): Category&MockObject
    {
        $parent = $this->createMock(Category::class);
        $parent->method('getStoreId')->willReturn($storeId);
        $parent->method('getPath')->willReturn('1/2/22');

        return $parent;
    }

    private function child(int $id, bool $isActive): Category&MockObject
    {
        $child = $this->createMock(Category::class);
        $child->method('getId')->willReturn($id);
        $child->method('getData')->willReturnCallback(
            static fn ($key = '') => $key === 'is_active' ? (int) $isActive : null
        );

        return $child;
    }
}
