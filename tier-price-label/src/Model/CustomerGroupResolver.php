<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\Model;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Customer\Model\Group;
use Magento\Framework\App\Http\Context as HttpContext;

/**
 * Current customer group, read the full-page-cache-safe way.
 *
 * Reading the group from Magento\Customer\Model\Session would start a session on a page
 * that is meant to be served from the FPC, and would make the answer invisible to the cache
 * key. The HTTP context is the value the FPC already varies on (Magento sets it in
 * Magento\Customer\Model\App\Action\ContextPlugin during dispatch), so anything derived from
 * it is automatically cached per group instead of leaking one group's ladder to another.
 */
class CustomerGroupResolver
{
    public function __construct(
        private readonly HttpContext $httpContext
    ) {
    }

    public function getCurrentGroupId(): int
    {
        $groupId = $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP);

        // The context value is absent on CLI and on the very first request of a session;
        // guests are the correct conservative default there.
        return $groupId === null ? Group::NOT_LOGGED_IN_ID : (int) $groupId;
    }
}
