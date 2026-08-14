<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Model\Resolver\Cache;

use Magento\Framework\GraphQl\Query\Resolver\IdentityInterface;
use Scr1be\OrderAttribution\Model\Source;

/**
 * Cache identity for `availableOrderSources`.
 *
 * One tag for the whole list rather than one per row, because the query has no arguments: there is
 * exactly one answer per store and any change to any source changes it. Per-row tags would be more
 * granular and would purge the same single entry.
 *
 * Returning an empty array when the registry is empty leaves the response uncached, which is the
 * behaviour core's own identities have (see `Magento\CmsGraphQl\Model\Resolver\Block\Identity`) and
 * the right one here: a merchant who has not populated the registry yet should see their first
 * source appear immediately rather than after a cache flush.
 */
class AvailableOrderSourcesIdentity implements IdentityInterface
{
    /**
     * @inheritDoc
     */
    public function getIdentities(array $resolvedData): array
    {
        return $resolvedData === [] ? [] : [Source::CACHE_TAG];
    }
}
