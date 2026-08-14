<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Model\CacheInvalidator;

class CacheInvalidatorTest extends TestCase
{
    private CacheContext&MockObject $cacheContext;
    private EventManager&MockObject $eventManager;
    private CacheInvalidator $invalidator;

    protected function setUp(): void
    {
        $this->cacheContext = $this->createMock(CacheContext::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->invalidator = new CacheInvalidator($this->cacheContext, $this->eventManager);
    }

    /**
     * The exact contract every full-page cache listens for: ids registered under the product cache
     * tag, then `clean_cache_by_tags` carrying the context as `object`. Both halves are asserted
     * because either one alone silently does nothing.
     */
    public function testRegistersProductIdsAndDispatchesTheTagEvent(): void
    {
        $this->cacheContext->expects($this->once())
            ->method('registerEntities')
            ->with(Product::CACHE_TAG, [4, 8]);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('clean_cache_by_tags', ['object' => $this->cacheContext]);

        $this->invalidator->invalidateProducts([4, 8]);
    }

    /**
     * A reconcile that changed nothing must not dispatch. The event is not free — the built-in
     * cache, Varnish and the GraphQL resolver cache all have observers on it.
     */
    public function testAnEmptySetDispatchesNothing(): void
    {
        $this->cacheContext->expects($this->never())->method('registerEntities');
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->invalidator->invalidateProducts([]);
    }
}
