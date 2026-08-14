<?php
declare(strict_types=1);

namespace Scr1be\SearchAutocomplete\Model;

/**
 * Everything a provider needs, resolved once by the resolver rather than five times by five
 * providers.
 *
 * The customer group id is in here because product prices depend on it and because a headless
 * request has no session to ask. `Magento_CustomerGraphQl` declares `customer_group_id` as an
 * extension attribute of the GraphQL context (`etc/extension_attributes.xml`), which is the only
 * trustworthy source for it in this area.
 */
final class SuggestionRequest
{
    /**
     * @param string $term
     * @param int $storeId
     * @param int $websiteId
     * @param int $customerGroupId
     * @param int $limit
     */
    public function __construct(
        public readonly string $term,
        public readonly int $storeId,
        public readonly int $websiteId,
        public readonly int $customerGroupId,
        public readonly int $limit
    ) {
    }
}
