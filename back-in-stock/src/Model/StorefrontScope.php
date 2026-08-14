<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The storefront's answer to "who is asking, and where".
 *
 * Four consumers need the same four numbers off the same two collaborators — the section source, the
 * account block, and both controllers — and every one of them would otherwise carry its own copy of
 * the "logged in? which website?" dance. The GraphQL resolver is the one caller that does *not* come
 * through here, because its answer comes from the query context rather than from a session.
 */
class StorefrontScope
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @throws NoSuchEntityException When the store is not resolvable, which on the storefront means
     *         the request never got as far as a store — there is nothing sensible to return.
     */
    public function current(): AlertScope
    {
        $store = $this->storeManager->getStore();

        return new AlertScope(
            $this->customerSession->isLoggedIn() ? (int)$this->customerSession->getCustomerId() : 0,
            (int)$this->customerSession->getCustomerGroupId(),
            (int)$store->getId(),
            (int)$store->getWebsiteId()
        );
    }
}
