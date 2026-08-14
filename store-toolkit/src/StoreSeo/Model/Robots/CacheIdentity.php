<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Robots;

use Magento\Framework\DataObject\IdentityInterface;

/**
 * A bag of cache tags shaped so that Magento's own invalidation machinery will accept it.
 *
 * `Magento\Framework\App\Cache\Tag\Strategy\Factory::getStrategy()` hands anything implementing
 * IdentityInterface to the Identifier strategy, which simply returns `getIdentities()`; the
 * `clean_cache_by_tags` observers then purge those tags. So the cheapest way to purge an arbitrary
 * tag set through the supported path is to hand the event an object that reports it.
 */
class CacheIdentity implements IdentityInterface
{
    /**
     * @var string[]
     */
    private array $identities;

    /**
     * @param string[] $identities
     */
    public function __construct(array $identities = [])
    {
        $this->identities = $identities;
    }

    /**
     * @return string[]
     */
    public function getIdentities()
    {
        return $this->identities;
    }
}
