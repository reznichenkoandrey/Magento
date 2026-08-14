<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Test\Unit\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Indexer\Category\Flat\State as FlatState;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CategoryCascade\Model\CascadeInvalidator;
use Scr1be\CategoryCascade\Model\CascadeLog;

class CascadeInvalidatorTest extends TestCase
{
    private CacheContext&MockObject $cacheContext;
    private EventManager&MockObject $eventManager;
    private IndexerRegistry&MockObject $indexerRegistry;
    private FlatState&MockObject $flatState;
    private CascadeLog&MockObject $log;
    private CascadeInvalidator $invalidator;

    protected function setUp(): void
    {
        $this->cacheContext = $this->createMock(CacheContext::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->indexerRegistry = $this->createMock(IndexerRegistry::class);
        $this->flatState = $this->createMock(FlatState::class);
        $this->log = $this->createMock(CascadeLog::class);

        $this->invalidator = new CascadeInvalidator(
            $this->cacheContext,
            $this->eventManager,
            $this->indexerRegistry,
            $this->flatState,
            $this->log
        );
    }

    public function testRegistersTheWholeSubtreeAsOneCacheBan(): void
    {
        $this->cacheContext->expects($this->once())
            ->method('registerEntities')
            ->with(Category::CACHE_TAG, [22, 31, 32]);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('clean_cache_by_tags', ['object' => $this->cacheContext]);

        $this->indexerRegistry->method('get')->willReturn($this->indexer(false));

        $this->invalidator->invalidate([22, 31, 32]);
    }

    public function testInvalidatesTheCategoryProductIndexWhenItUpdatesOnSave(): void
    {
        $indexer = $this->indexer(false);
        $indexer->expects($this->once())->method('invalidate');

        $this->indexerRegistry->expects($this->once())
            ->method('get')
            ->with('catalog_category_product')
            ->willReturn($indexer);

        $this->invalidator->invalidate([31]);
    }

    /**
     * A scheduled indexer is already being fed by mview triggers on the tables this module writes.
     * Invalidating it would swap a partial reindex for a full one.
     */
    public function testLeavesAScheduledIndexerAlone(): void
    {
        $indexer = $this->indexer(true);
        $indexer->expects($this->never())->method('invalidate');
        $this->indexerRegistry->method('get')->willReturn($indexer);

        $this->invalidator->invalidate([31]);
    }

    /**
     * The flat-disabled branch is covered by the single-get expectation above: an unused indexer
     * left showing "Reindex required" is noise no reindex explains.
     */
    public function testInvalidatesTheFlatIndexAsWellWhenFlatIsEnabled(): void
    {
        $this->flatState->method('isFlatEnabled')->willReturn(true);

        $this->indexerRegistry->expects($this->exactly(2))
            ->method('get')
            ->willReturn($this->indexer(false));

        $this->invalidator->invalidate([31]);
    }

    /**
     * The cascade has already committed by this point; an unreadable indexer is a line in the log,
     * not a reason to abandon the rest of the invalidation.
     */
    public function testLogsAndContinuesWhenAnIndexerCannotBeRead(): void
    {
        $this->flatState->method('isFlatEnabled')->willReturn(true);
        $this->indexerRegistry->method('get')
            ->willThrowException(new \InvalidArgumentException('unknown indexer'));

        $this->log->expects($this->exactly(2))->method('indexerInvalidationFailed');

        $this->invalidator->invalidate([31]);
    }

    public function testDoesNothingWithoutIds(): void
    {
        $this->cacheContext->expects($this->never())->method('registerEntities');
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->invalidator->invalidate([]);
    }

    private function indexer(bool $scheduled): IndexerInterface&MockObject
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('isScheduled')->willReturn($scheduled);

        return $indexer;
    }
}
