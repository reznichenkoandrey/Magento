<?php
declare(strict_types=1);

namespace Scr1be\GuestRegistration\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\State\InputMismatchException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderCustomerManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The decision ladder that turns a placed guest order into a customer account.
 *
 * Every rung is explicit and every rung returns, because the interesting part of auto-registration
 * is not the happy path — it is the five ways the happy path does not apply. An implementation that
 * collapses them into `if (!$order->getCustomerId()) { createAccount(); }` will, on a real
 * storefront, try to create an account for an email that already has one and lose the order's
 * association to it.
 */
class GuestRegistrar
{
    /**
     * Fired after a brand new account has been created from an order.
     *
     * Core's own registration event, `customer_register_success`, is deliberately *not* reused. Its
     * payload is `['account_controller' => $this, 'customer' => $customer]`
     * (Magento\Customer\Controller\Account\CreatePost::execute), and `account_controller` is the
     * storefront controller instance. There is no controller here — this runs inside a GraphQL
     * mutation — so dispatching that event would hand every existing listener a payload key it is
     * entitled to dereference and cannot. A module-namespaced event with an honest payload is the
     * only version of this that does not break other people's observers.
     */
    public const EVENT_CUSTOMER_CREATED = 'scr1be_guest_registration_customer_created';

    /**
     * Fired after an order has been attached to an account that already existed.
     */
    public const EVENT_ORDER_LINKED = 'scr1be_guest_registration_order_linked';

    /**
     * @param Config $config
     * @param CustomerRepositoryInterface $customerRepository
     * @param OrderCustomerManagementInterface $orderCustomerManagement
     * @param OrderRepositoryInterface $orderRepository
     * @param StoreManagerInterface $storeManager
     * @param EventManagerInterface $eventManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly OrderCustomerManagementInterface $orderCustomerManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly EventManagerInterface $eventManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Walk the ladder for one freshly placed order.
     *
     * Never throws. The order has already been placed and paid for by the time this runs; letting an
     * account-creation problem escape would roll a completed checkout back over a side effect.
     *
     * @param OrderInterface $order
     * @return RegistrationOutcome
     */
    public function register(OrderInterface $order): RegistrationOutcome
    {
        $storeId = (int)$order->getStoreId();

        if (!$this->config->isEnabled($storeId)) {
            return RegistrationOutcome::DISABLED;
        }

        if ($order->getCustomerId()) {
            return RegistrationOutcome::SKIPPED_LOGGED_IN;
        }

        $email = trim((string)$order->getCustomerEmail());
        if ($email === '') {
            return RegistrationOutcome::SKIPPED_NO_EMAIL;
        }

        try {
            $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
            $existing = $this->findCustomer($email, $websiteId);

            if ($existing !== null) {
                if (!$this->config->shouldLinkExisting($storeId)) {
                    return RegistrationOutcome::SKIPPED_EXISTING_ACCOUNT;
                }

                $this->link($order, $existing);

                return RegistrationOutcome::LINKED_EXISTING;
            }

            return $this->create($order, $email, $websiteId);
        } catch (\Throwable $e) {
            // The order is real and paid for. Whatever happened here, it is a support ticket, not a
            // failed checkout.
            $this->logger->error(
                sprintf(
                    'Scr1be_GuestRegistration: could not register guest for order %s: %s',
                    (string)$order->getIncrementId(),
                    $e->getMessage()
                ),
                ['exception' => $e]
            );

            return RegistrationOutcome::FAILED;
        }
    }

    /**
     * Create the account, then reconcile if somebody else created it first.
     *
     * `OrderCustomerManagementInterface::create()` is core's own "promote this order to an account"
     * service: it extracts the customer from the order, calls
     * `AccountManagementInterface::createAccount($customer)` with no password, and then sets
     * `customer_id` and `customer_is_guest = 0` on the order and saves it
     * (Magento\Sales\Model\Order\CustomerManagement::create). Passing no password matters — core's
     * `createAccount()` turns a null password into a null hash, and `sendEmailConfirmation()` then
     * picks the NEW_ACCOUNT_EMAIL_REGISTERED_NO_PASSWORD template, which is the "set your password"
     * mail. Reimplementing any of that would be reimplementing it worse.
     *
     * The catch is the race. Two concurrent checkouts from the same address — a shopper who
     * double-taps, or an app retrying a timed-out mutation — both find no account and both try to
     * create one. The second `customerRepository->save()` hits the unique index, core translates
     * the AlreadyExistsException into an InputMismatchException, and the honest response is not to
     * fail but to accept that the account now exists and attach the order to it.
     *
     * @param OrderInterface $order
     * @param string $email
     * @param int $websiteId
     * @return RegistrationOutcome
     * @throws \Throwable
     */
    private function create(OrderInterface $order, string $email, int $websiteId): RegistrationOutcome
    {
        try {
            $customer = $this->orderCustomerManagement->create((int)$order->getEntityId());
        } catch (InputMismatchException $e) {
            $winner = $this->findCustomer($email, $websiteId);
            if ($winner === null) {
                // The address is taken but not on this website, which means the collision came from
                // somewhere this module cannot reason about. Leave the order as a guest order.
                throw $e;
            }

            $this->link($order, $winner);

            return RegistrationOutcome::LINKED_EXISTING;
        }

        // Core's service loads its own copy of the order, stamps it and saves it, so the instance the
        // event handed us is now behind the database. Re-reading gives listeners an order whose
        // customer_id agrees with the customer in the same payload.
        $this->eventManager->dispatch(
            self::EVENT_CUSTOMER_CREATED,
            ['customer' => $customer, 'order' => $this->orderRepository->get((int)$order->getEntityId())]
        );

        return RegistrationOutcome::CREATED;
    }

    /**
     * Attach an already-placed order to an existing account.
     *
     * The group id is copied across as well as the id: `sales_order.customer_group_id` is a
     * historical record of who the shopper was when they ordered, and leaving it on NOT LOGGED IN
     * while `customer_id` points at a wholesale account produces an order that reports two different
     * customers depending on which column a report reads.
     *
     * @param OrderInterface $order
     * @param CustomerInterface $customer
     * @return void
     */
    private function link(OrderInterface $order, CustomerInterface $customer): void
    {
        $order->setCustomerId((int)$customer->getId());
        $order->setCustomerIsGuest(0);
        $order->setCustomerGroupId((int)$customer->getGroupId());
        $order->setCustomerFirstname($order->getCustomerFirstname() ?: $customer->getFirstname());
        $order->setCustomerLastname($order->getCustomerLastname() ?: $customer->getLastname());

        $this->orderRepository->save($order);

        $this->eventManager->dispatch(
            self::EVENT_ORDER_LINKED,
            ['customer' => $customer, 'order' => $order]
        );
    }

    /**
     * Look an account up by email within one website, or null if there is none.
     *
     * Website-scoped because that is the scope customer accounts are unique in: the same address can
     * legitimately be two different people on two different websites, and a global lookup would
     * hand a shopper on one brand the order history of a shopper on another.
     *
     * @param string $email
     * @param int $websiteId
     * @return CustomerInterface|null
     */
    private function findCustomer(string $email, int $websiteId): ?CustomerInterface
    {
        try {
            return $this->customerRepository->get($email, $websiteId);
        } catch (NoSuchEntityException) {
            return null;
        }
    }
}
