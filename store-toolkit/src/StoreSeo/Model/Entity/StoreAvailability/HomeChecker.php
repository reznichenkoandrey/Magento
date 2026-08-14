<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Entity\StoreAvailability;

/**
 * Every active store has a home page, so the only gate that matters for it — the store being
 * active — has already been applied by the time a checker is consulted.
 */
class HomeChecker implements AvailabilityCheckerInterface
{
    public function isAvailable(int $entityId, int $storeId): bool
    {
        return true;
    }
}
