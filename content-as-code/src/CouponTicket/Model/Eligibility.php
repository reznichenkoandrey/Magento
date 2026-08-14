<?php
declare(strict_types=1);

namespace Scr1be\CouponTicket\Model;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Customer\Model\GroupManagement;
use Magento\Framework\App\Http\Context as HttpContext;

/**
 * "Is this customer's group on the rule?", answered without breaking the page cache.
 *
 * The obvious implementation reads the customer session, and the obvious implementation is wrong:
 * a session read inside a cacheable block gives whichever customer warmed the cache their answer
 * for everybody else. The correct source is `Magento\Framework\App\Http\Context`, and it works
 * because the full-page cache key is derived from it —
 * `Magento\Framework\App\PageCache\Identifier::getValue()` hashes the request's vary cookie or
 * `Context::getVaryString()`, and `Magento\Customer\Model\App\Action\ContextPlugin::beforeExecute()`
 * puts the customer group id into that context on every action. Group 1 and group 3 therefore get
 * different cached pages by construction.
 *
 * `ContextPlugin` passes `GroupManagement::NOT_LOGGED_IN_ID` as the *default*, and
 * `Context::getData()` drops values equal to their default before hashing. So guests share one
 * cache entry — which is right, and is why this class treats a missing context value as the guest
 * group rather than as an error.
 */
class Eligibility
{
    public function __construct(
        private readonly HttpContext $httpContext
    ) {
    }

    public function getCurrentGroupId(): int
    {
        $group = $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP);

        // `getValue()` returns the registered default when nothing was set and null when nothing
        // was registered either — which happens in any context where `ContextPlugin` did not run,
        // such as a console command rendering a block. Guest is the safe reading of "unknown".
        return $group === null ? GroupManagement::NOT_LOGGED_IN_ID : (int)$group;
    }

    /**
     * @param int[] $eligibleGroupIds The rule's `customer_group_ids`.
     */
    public function isEligible(array $eligibleGroupIds): bool
    {
        // A rule with no groups applies to nobody. `customer_group_ids` carries a `required-entry`
        // validation rule in Magento_SalesRule/view/adminhtml/ui_component/sales_rule_form.xml, so
        // an empty list means the rule was written by something other than the admin form — and
        // "show the coupon to everyone" is the wrong way to resolve that ambiguity.
        if ($eligibleGroupIds === []) {
            return false;
        }

        return in_array($this->getCurrentGroupId(), array_map('intval', $eligibleGroupIds), true);
    }
}
