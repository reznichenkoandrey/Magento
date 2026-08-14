<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Robots;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Robots\Model\Config\Value as CoreRobotsConfigValue;

/**
 * Drops the cached copies of `/robots.txt` after the content behind it changes.
 *
 * Publishing a file is only half the job: core still serves the same path from a controller, and
 * that response is cached. `Magento\Robots\Block\Data::getIdentities()` returns
 * `Magento\Robots\Model\Config\Value::CACHE_TAG . '_' . $storeId` — the tag is built per *store*
 * even though the content behind it is website-scoped, so one website's change has to purge every
 * store under it.
 */
class CacheInvalidator
{
    private CacheInterface $cache;

    private EventManager $eventManager;

    private CacheIdentityFactory $cacheIdentityFactory;

    public function __construct(
        CacheInterface $cache,
        EventManager $eventManager,
        CacheIdentityFactory $cacheIdentityFactory
    ) {
        $this->cache = $cache;
        $this->eventManager = $eventManager;
        $this->cacheIdentityFactory = $cacheIdentityFactory;
    }

    /**
     * @param int[] $storeIds Every store of the website whose robots.txt just changed.
     */
    public function invalidate(array $storeIds): void
    {
        $tags = $this->buildTags($storeIds);

        if ($tags === []) {
            return;
        }

        // Two caches, two mechanisms. The default cache (where the robots block's own output
        // lands) is cleaned directly; the full page cache only listens to `clean_cache_by_tags`,
        // and only for objects that can report identities.
        $this->cache->clean($tags);

        $this->eventManager->dispatch(
            'clean_cache_by_tags',
            ['object' => $this->cacheIdentityFactory->create(['identities' => $tags])]
        );
    }

    /**
     * @param int[] $storeIds
     * @return string[]
     */
    private function buildTags(array $storeIds): array
    {
        $tags = [];

        foreach ($storeIds as $storeId) {
            $tags[] = CoreRobotsConfigValue::CACHE_TAG . '_' . (int) $storeId;
        }

        return array_values(array_unique($tags));
    }
}
