<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * The group the customer is actually in, read from the customer record.
 *
 * Deliberately not the session's copy. `Customer\Model\Session::getCustomerGroupId()` answers
 * from session storage, which is the value this module exists to distrust.
 *
 * The repository keeps a per-request registry, so both callers — the section source on a
 * /customer/section/load request and the place-order guard on a checkout request — pay for at
 * most one customer load, and usually for none, because the session has already loaded the same
 * customer through the same registry earlier in the request.
 *
 * Every failure resolves to null, and every caller reads null as "do nothing". A repository that
 * cannot answer is an infrastructure problem; signing a customer out or refusing their order
 * because of one would convert it into a customer-facing one.
 */
class GroupResolver
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return int|null null when the customer cannot be read.
     */
    public function resolveStoredGroupId(int $customerId): ?int
    {
        if ($customerId <= 0) {
            return null;
        }

        try {
            $groupId = $this->customerRepository->getById($customerId)->getGroupId();
        } catch (LocalizedException $error) {
            // NoSuchEntityException included: a session pointing at a deleted customer is core's
            // problem to notice, and it notices on the next authenticated action.
            $this->logger->warning(
                'scr1be_customer_group_guard: could not read the stored customer group',
                ['customer_id' => $customerId, 'exception' => $error]
            );

            return null;
        }

        return $groupId === null ? null : (int) $groupId;
    }
}
