<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Model;

use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Reads the `is_carder` flag for a customer.
 *
 * The read goes through CustomerRegistry — the customer *model* — and not through
 * CustomerRepositoryInterface::getById()->getCustomAttribute(). That is deliberate and it is the
 * only correct option here: Plugin\Customer\HideFlagFromApiMetadata strips this very attribute
 * from the custom-attribute metadata in the webapi_rest and graphql areas, which are exactly the
 * areas checkout runs in. A repository-based read would therefore return null precisely where the
 * guard has to work. The model carries the raw EAV row and is unaffected by that filtering.
 *
 * It also costs nothing extra: PlaceOrderGuard reaches the flag through Quote::getCustomer(),
 * which has already put the customer in this same registry.
 */
class FlagResolver implements ResetAfterRequestInterface
{
    public const ATTRIBUTE_CODE = 'is_carder';

    /**
     * Request-scoped memo. placeOrder() and submit() can both run in one request, and the answer
     * cannot change between them.
     *
     * @var array<int, bool>
     */
    private array $resolved = [];

    public function __construct(
        private readonly CustomerRegistry $customerRegistry,
        private readonly GuardLog $log
    ) {
    }

    public function isFlagged(int $customerId): bool
    {
        if ($customerId <= 0) {
            return false;
        }

        if (!array_key_exists($customerId, $this->resolved)) {
            $this->resolved[$customerId] = $this->read($customerId);
        }

        return $this->resolved[$customerId];
    }

    /**
     * Fail-open by design. If the customer row cannot be read, the storefront is already in
     * trouble and core is about to fail on its own; turning an infrastructure hiccup into a
     * store-wide checkout outage would be a much larger incident than the one this module
     * prevents. The failure is loud in the log instead of loud on the storefront.
     */
    private function read(int $customerId): bool
    {
        try {
            $customer = $this->customerRegistry->retrieve($customerId);
        } catch (NoSuchEntityException) {
            // A quote pointing at a deleted customer: nothing to flag, and core will handle it.
            return false;
        } catch (\Throwable $error) {
            $this->log->lookupFailed($customerId, $error);

            return false;
        }

        return (bool) (int) $customer->getData(self::ATTRIBUTE_CODE);
    }

    /**
     * Under an application server the object graph outlives the request. A merchant who flags a
     * customer must not have to wait for a worker recycle for the flag to take effect.
     */
    public function _resetState(): void
    {
        $this->resolved = [];
    }
}
