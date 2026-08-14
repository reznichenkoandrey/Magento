<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Test\Unit\Model\Robots;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreSeo\Model\Robots\CacheIdentity;
use Scr1be\StoreSeo\Model\Robots\CacheIdentityFactory;
use Scr1be\StoreSeo\Model\Robots\CacheInvalidator;

class CacheInvalidatorTest extends TestCase
{
    /**
     * @var CacheInterface&MockObject
     */
    private $cache;

    /**
     * @var EventManager&MockObject
     */
    private $eventManager;

    /**
     * @var CacheIdentityFactory&MockObject
     */
    private $identityFactory;

    private CacheInvalidator $invalidator;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->identityFactory = $this->createMock(CacheIdentityFactory::class);

        $this->identityFactory->method('create')
            ->willReturnCallback(static fn (array $data) => new CacheIdentity($data['identities'] ?? []));

        $this->invalidator = new CacheInvalidator($this->cache, $this->eventManager, $this->identityFactory);
    }

    public function testUsesTheTagShapeCoreEmitsForRobots(): void
    {
        // Magento\Robots\Block\Data::getIdentities() returns
        // Magento\Robots\Model\Config\Value::CACHE_TAG . '_' . storeId — 'robots_1' — so anything
        // else here would purge nothing at all and fail silently.
        $this->cache->expects(self::once())->method('clean')->with(['robots_1', 'robots_2']);

        $this->eventManager->expects(self::once())
            ->method('dispatch')
            ->with(
                'clean_cache_by_tags',
                self::callback(static function (array $data): bool {
                    return $data['object'] instanceof CacheIdentity
                        && $data['object']->getIdentities() === ['robots_1', 'robots_2'];
                })
            );

        $this->invalidator->invalidate([1, 2]);
    }

    public function testDuplicateStoreIdsProduceOneTag(): void
    {
        $this->cache->expects(self::once())->method('clean')->with(['robots_3']);

        $this->invalidator->invalidate([3, 3]);
    }

    public function testNoStoresMeansNoPurgeAtAll(): void
    {
        $this->cache->expects(self::never())->method('clean');
        $this->eventManager->expects(self::never())->method('dispatch');

        $this->invalidator->invalidate([]);
    }
}
