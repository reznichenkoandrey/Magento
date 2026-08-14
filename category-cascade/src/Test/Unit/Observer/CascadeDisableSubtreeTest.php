<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Test\Unit\Observer;

use Magento\Catalog\Model\Category;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CategoryCascade\Model\CascadeGuard;
use Scr1be\CategoryCascade\Model\CascadeInvalidator;
use Scr1be\CategoryCascade\Model\CascadeLog;
use Scr1be\CategoryCascade\Model\CascadeResult;
use Scr1be\CategoryCascade\Model\SubtreeDisabler;
use Scr1be\CategoryCascade\Observer\CascadeDisableSubtree;

class CascadeDisableSubtreeTest extends TestCase
{
    private CascadeGuard&MockObject $guard;
    private SubtreeDisabler&MockObject $disabler;
    private CascadeInvalidator&MockObject $invalidator;
    private CascadeLog&MockObject $log;
    private CascadeDisableSubtree $observer;

    protected function setUp(): void
    {
        $this->guard = $this->createMock(CascadeGuard::class);
        $this->disabler = $this->createMock(SubtreeDisabler::class);
        $this->invalidator = $this->createMock(CascadeInvalidator::class);
        $this->log = $this->createMock(CascadeLog::class);

        $this->observer = new CascadeDisableSubtree(
            $this->guard,
            $this->disabler,
            $this->invalidator,
            $this->log
        );
    }

    public function testIgnoresAnEventWithoutACategory(): void
    {
        $this->guard->expects($this->never())->method('shouldCascade');

        $this->observer->execute(new Observer(['event' => new Event()]));
    }

    public function testDoesNothingWhenTheGuardRejectsTheSave(): void
    {
        $this->guard->method('shouldCascade')->willReturn(false);
        $this->disabler->expects($this->never())->method('disableSubtree');

        $this->observer->execute($this->observerFor($this->category()));
    }

    /**
     * The parent is invalidated alongside its subtree: its own page still lists the children that
     * just disappeared.
     */
    public function testInvalidatesTheParentTogetherWithItsSubtree(): void
    {
        $this->guard->method('shouldCascade')->willReturn(true);
        $this->disabler->method('disableSubtree')
            ->willReturn(new CascadeResult([31, 32], [31], 0));

        $this->log->expects($this->once())->method('cascadeCompleted');
        $this->invalidator->expects($this->once())
            ->method('invalidate')
            ->with([22, 31, 32]);

        $this->observer->execute($this->observerFor($this->category()));
    }

    public function testSkipsInvalidationWhenNothingChanged(): void
    {
        $this->guard->method('shouldCascade')->willReturn(true);
        $this->disabler->method('disableSubtree')
            ->willReturn(new CascadeResult([31, 32], [], 0));

        $this->log->expects($this->never())->method('cascadeCompleted');
        $this->invalidator->expects($this->never())->method('invalidate');

        $this->observer->execute($this->observerFor($this->category()));
    }

    /**
     * The admin's save is already committed and its response is on the way out; a cascade failure
     * cannot be allowed to turn a successful save into an error page.
     */
    public function testSwallowsAndLogsAFailedCascade(): void
    {
        $this->guard->method('shouldCascade')->willReturn(true);
        $this->disabler->method('disableSubtree')
            ->willThrowException(new \RuntimeException('deadlock'));

        $this->log->expects($this->once())
            ->method('cascadeFailed')
            ->with(22, 0, $this->isInstanceOf(\RuntimeException::class));
        $this->invalidator->expects($this->never())->method('invalidate');

        $this->observer->execute($this->observerFor($this->category()));
    }

    /**
     * A committed cascade that failed to invalidate is a different incident from one that never
     * wrote anything — it is fixed with a cache flush, not by saving the category again.
     */
    public function testLogsAnInvalidationFailureSeparately(): void
    {
        $this->guard->method('shouldCascade')->willReturn(true);
        $this->disabler->method('disableSubtree')
            ->willReturn(new CascadeResult([31], [31], 0));
        $this->invalidator->method('invalidate')
            ->willThrowException(new \RuntimeException('cache backend down'));

        $this->log->expects($this->never())->method('cascadeFailed');
        $this->log->expects($this->once())->method('cacheInvalidationFailed');

        $this->observer->execute($this->observerFor($this->category()));
    }

    private function category(): Category&MockObject
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn(22);
        $category->method('getStoreId')->willReturn(0);

        return $category;
    }

    private function observerFor(Category $category): Observer
    {
        return new Observer(['event' => new Event(['category' => $category])]);
    }
}
