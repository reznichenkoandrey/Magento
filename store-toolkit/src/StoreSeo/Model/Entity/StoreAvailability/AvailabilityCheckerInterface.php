<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Entity\StoreAvailability;

/**
 * Answers "does this entity exist, for a visitor, in that store view".
 *
 * A URL rewrite alone is not that answer. Rewrites outlive the thing they point at — disabling a
 * product does not delete its rewrite — so an hreflang set built from rewrites only would advertise
 * alternates that answer 404. Every entity type therefore gets a second gate that loads the entity
 * in the target store's scope and asks whether a visitor would be shown it.
 */
interface AvailabilityCheckerInterface
{
    public function isAvailable(int $entityId, int $storeId): bool;
}
