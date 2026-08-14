<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Customer as CustomerModel;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Scr1be\CustomerGroupGuard\Model\Config;
use Scr1be\CustomerGroupGuard\Model\GroupCookie;

/**
 * Records the group at the moment the browser starts being served as this customer.
 *
 * Login is the only moment where "the group the customer is in" and "the group this browser is
 * being served under" are guaranteed to be the same value, which is what makes it the only
 * honest place to write the cookie. Every later write would be recording an assumption.
 *
 * The group comes off the event's own customer rather than out of the session. Both are correct
 * at this instant, and the event's payload is the one that cannot be affected by anything that
 * runs between the session being populated and this observer being called.
 *
 * `customer_login` carries the customer model; a caller that reaches the session through the data
 * object dispatches the same event with the model it built. Both are accepted, because both
 * answer getGroupId() and a login that silently skips the write is a soft path that silently
 * never fires.
 */
class RecordGroupOnLogin implements ObserverInterface
{
    private const EVENT_DATA_CUSTOMER = 'customer';

    public function __construct(
        private readonly Config $config,
        private readonly GroupCookie $groupCookie
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isForceLogoutEnabled()) {
            return;
        }

        $customer = $observer->getEvent()->getData(self::EVENT_DATA_CUSTOMER);
        if (!$customer instanceof CustomerModel && !$customer instanceof CustomerInterface) {
            return;
        }

        $groupId = $customer->getGroupId();
        if ($groupId === null || $groupId === '') {
            // A login without a group is not a state this module can describe. Leaving the cookie
            // absent means the section source heals it on the next request, which is the same
            // outcome as a browser that dropped it.
            return;
        }

        $this->groupCookie->write((int) $groupId);
    }
}
