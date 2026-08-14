<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Robots;

use Magento\Framework\ObjectManagerInterface;

/**
 * Hand-written rather than generated.
 *
 * The generated factory would be identical, but generated classes do not exist until
 * `setup:di:compile` has run, which makes them awkward to mock in a unit suite that runs against
 * source. Writing the four lines out keeps the test suite runnable straight from a checkout.
 */
class CacheIdentityFactory
{
    private ObjectManagerInterface $objectManager;

    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data = []): CacheIdentity
    {
        return $this->objectManager->create(CacheIdentity::class, $data);
    }
}
