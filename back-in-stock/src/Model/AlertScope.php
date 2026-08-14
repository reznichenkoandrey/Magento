<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model;

/**
 * Who is being served, and where.
 *
 * The provider is called from three places that discover these four numbers in three different ways
 * — a customer-data section reads them off the customer session, an account block off the same
 * session but a different store, a GraphQL resolver off the query context — so the provider takes
 * them rather than reaching for a session it may not be inside.
 *
 * The customer group is here because `Magento\Catalog\Model\ResourceModel\Product\Collection::addPriceData()`
 * falls back to `$this->_customerSession->getCustomerGroupId()` when it is not given one, and a
 * GraphQL request resolved against the storefront session's group would price the response for the
 * wrong customer.
 */
final class AlertScope
{
    public function __construct(
        public readonly int $customerId,
        public readonly int $customerGroupId,
        public readonly int $storeId,
        public readonly int $websiteId
    ) {
    }

    public function isIdentified(): bool
    {
        return $this->customerId > 0;
    }
}
