<?php
declare(strict_types=1);

namespace Scr1be\GuestRegistration\Test\Unit\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\State\InputMismatchException;
use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderCustomerManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\GuestRegistration\Model\Config;
use Scr1be\GuestRegistration\Model\GuestRegistrar;
use Scr1be\GuestRegistration\Model\RegistrationOutcome;

/**
 * The ladder has six exits and every one of them is a decision somebody could get wrong.
 */
class GuestRegistrarTest extends TestCase
{
    private const STORE_ID = 3;
    private const WEBSITE_ID = 1;
    private const ORDER_ID = 42;

    private Config&MockObject $config;
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private OrderCustomerManagementInterface&MockObject $orderCustomerManagement;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private EventManagerInterface&MockObject $eventManager;
    private LoggerInterface&MockObject $logger;
    private GuestRegistrar $registrar;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->orderCustomerManagement = $this->createMock(OrderCustomerManagementInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->eventManager = $this->createMock(EventManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with(self::STORE_ID)->willReturn($store);

        $this->registrar = new GuestRegistrar(
            $this->config,
            $this->customerRepository,
            $this->orderCustomerManagement,
            $this->orderRepository,
            $storeManager,
            $this->eventManager,
            $this->logger
        );
    }

    public function testReturnsDisabledWhenSwitchedOffForTheStore(): void
    {
        $this->config->method('isEnabled')->with(self::STORE_ID)->willReturn(false);
        $this->orderCustomerManagement->expects($this->never())->method('create');

        $this->assertSame(
            RegistrationOutcome::DISABLED,
            $this->registrar->register($this->order(['customer_email' => 'a@example.com']))
        );
    }

    public function testSkipsAnOrderThatAlreadyBelongsToACustomer(): void
    {
        $this->enable();
        $this->orderCustomerManagement->expects($this->never())->method('create');

        $order = $this->order(['customer_id' => 7, 'customer_email' => 'a@example.com']);

        $this->assertSame(RegistrationOutcome::SKIPPED_LOGGED_IN, $this->registrar->register($order));
    }

    /**
     * A whitespace-only email is not an email. Trimming before the emptiness check is the difference
     * between skipping and asking core to create an account with a blank address.
     */
    public function testSkipsAnOrderWithNoUsableEmail(): void
    {
        $this->enable();
        $this->orderCustomerManagement->expects($this->never())->method('create');

        $this->assertSame(
            RegistrationOutcome::SKIPPED_NO_EMAIL,
            $this->registrar->register($this->order(['customer_email' => '   ']))
        );
    }

    public function testCreatesAnAccountWhenTheEmailIsUnknown(): void
    {
        $this->enable();
        $this->customerRepository->method('get')
            ->with('new@example.com', self::WEBSITE_ID)
            ->willThrowException(new NoSuchEntityException(new Phrase('nope')));

        $created = $this->customer(11, 1);
        $this->orderCustomerManagement->expects($this->once())
            ->method('create')
            ->with(self::ORDER_ID)
            ->willReturn($created);

        $persisted = $this->order(['customer_email' => 'new@example.com', 'customer_id' => 11]);
        $this->orderRepository->method('get')->with(self::ORDER_ID)->willReturn($persisted);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with(
                GuestRegistrar::EVENT_CUSTOMER_CREATED,
                ['customer' => $created, 'order' => $persisted]
            );

        $this->assertSame(
            RegistrationOutcome::CREATED,
            $this->registrar->register($this->order(['customer_email' => 'new@example.com']))
        );
    }

    public function testLinksAnOrderToAnAccountThatAlreadyExists(): void
    {
        $this->enable();
        $existing = $this->customer(11, 4);
        $this->customerRepository->method('get')->willReturn($existing);
        $this->orderCustomerManagement->expects($this->never())->method('create');

        $order = $this->order(['customer_email' => 'known@example.com']);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with(GuestRegistrar::EVENT_ORDER_LINKED, ['customer' => $existing, 'order' => $order]);

        $this->assertSame(RegistrationOutcome::LINKED_EXISTING, $this->registrar->register($order));
        $this->assertSame(11, $order->getCustomerId());
        $this->assertSame(0, $order->getCustomerIsGuest());
        $this->assertSame(4, $order->getCustomerGroupId(), 'The order must record the group it was placed under');
    }

    public function testDoesNotLinkWhenLinkingIsSwitchedOff(): void
    {
        $this->enable(linkExisting: false);
        $this->customerRepository->method('get')->willReturn($this->customer(11, 4));
        $this->orderRepository->expects($this->never())->method('save');

        $this->assertSame(
            RegistrationOutcome::SKIPPED_EXISTING_ACCOUNT,
            $this->registrar->register($this->order(['customer_email' => 'known@example.com']))
        );
    }

    /**
     * The race: nobody had the address when we looked, somebody had it by the time we wrote. Core
     * turns the unique-index violation into an InputMismatchException, and the right answer is to
     * accept the winner rather than to fail the side effect.
     */
    public function testReFindsAndLinksWhenAConcurrentRequestWonTheEmail(): void
    {
        $this->enable();
        $winner = $this->customer(11, 1);
        $lookups = 0;
        $this->customerRepository->method('get')
            ->willReturnCallback(static function () use (&$lookups, $winner) {
                if (++$lookups === 1) {
                    throw new NoSuchEntityException(new Phrase('nope'));
                }

                return $winner;
            });
        $this->orderCustomerManagement->method('create')
            ->willThrowException(new InputMismatchException(new Phrase('taken')));

        $order = $this->order(['customer_email' => 'race@example.com']);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->assertSame(RegistrationOutcome::LINKED_EXISTING, $this->registrar->register($order));
        $this->assertSame(11, $order->getCustomerId());
        $this->assertSame(2, $lookups, 'The winner has to be looked up again, not assumed');
    }

    /**
     * The address is taken but not on this website, so re-finding turns up nothing. That is not a
     * race this module understands, and inventing a link would attach the order to the wrong person.
     */
    public function testFailsWhenTheCollidingAccountCannotBeFound(): void
    {
        $this->enable();
        $this->customerRepository->method('get')
            ->willThrowException(new NoSuchEntityException(new Phrase('nope')));
        $this->orderCustomerManagement->method('create')
            ->willThrowException(new InputMismatchException(new Phrase('taken')));
        $this->orderRepository->expects($this->never())->method('save');
        $this->logger->expects($this->once())->method('error');

        $this->assertSame(
            RegistrationOutcome::FAILED,
            $this->registrar->register($this->order(['customer_email' => 'race@example.com']))
        );
    }

    /**
     * The order is placed and paid for by the time this runs. Nothing thrown in here may escape.
     */
    public function testSwallowsAndLogsAnythingElseThatThrows(): void
    {
        $this->enable();
        $this->customerRepository->method('get')
            ->willThrowException(new NoSuchEntityException(new Phrase('nope')));
        $this->orderCustomerManagement->method('create')
            ->willThrowException(new \RuntimeException('database is on fire'));
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('database is on fire'), $this->arrayHasKey('exception'));

        $this->assertSame(
            RegistrationOutcome::FAILED,
            $this->registrar->register($this->order(['customer_email' => 'boom@example.com']))
        );
    }

    public function testOnlyACreatedAccountCountsAsANewAccount(): void
    {
        $this->assertTrue(RegistrationOutcome::CREATED->isNewAccount());

        foreach (RegistrationOutcome::cases() as $case) {
            if ($case !== RegistrationOutcome::CREATED) {
                $this->assertFalse($case->isNewAccount(), $case->value . ' must not read as a new account');
            }
        }
    }

    private function enable(bool $linkExisting = true): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('shouldLinkExisting')->willReturn($linkExisting);
    }

    /**
     * A stateful OrderInterface double.
     *
     * The registrar both reads and writes the order, and the assertions are about what it wrote, so
     * a plain mock returning fixed values would test nothing. A DataObject behind the mock gives the
     * handful of accessors the registrar touches real read-after-write behaviour without dragging
     * `Magento\Sales\Model\Order`'s thirty-argument constructor into a unit test.
     *
     * @param array<string, mixed> $data
     * @return OrderInterface&MockObject
     */
    private function order(array $data): OrderInterface
    {
        $state = new DataObject($data + ['entity_id' => self::ORDER_ID, 'store_id' => self::STORE_ID]);

        $order = $this->createMock(OrderInterface::class);
        $getters = [
            'getEntityId' => 'entity_id',
            'getIncrementId' => 'increment_id',
            'getStoreId' => 'store_id',
            'getCustomerId' => 'customer_id',
            'getCustomerEmail' => 'customer_email',
            'getCustomerIsGuest' => 'customer_is_guest',
            'getCustomerGroupId' => 'customer_group_id',
            'getCustomerFirstname' => 'customer_firstname',
            'getCustomerLastname' => 'customer_lastname',
        ];
        foreach ($getters as $method => $key) {
            $order->method($method)->willReturnCallback(static fn () => $state->getData($key));
        }

        $setters = [
            'setCustomerId' => 'customer_id',
            'setCustomerIsGuest' => 'customer_is_guest',
            'setCustomerGroupId' => 'customer_group_id',
            'setCustomerFirstname' => 'customer_firstname',
            'setCustomerLastname' => 'customer_lastname',
        ];
        foreach ($setters as $method => $key) {
            $order->method($method)->willReturnCallback(
                static function ($value) use ($state, $key, &$order) {
                    $state->setData($key, $value);

                    return $order;
                }
            );
        }

        return $order;
    }

    private function customer(int $id, int $groupId): CustomerInterface&MockObject
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);
        $customer->method('getGroupId')->willReturn($groupId);
        $customer->method('getFirstname')->willReturn('Ada');
        $customer->method('getLastname')->willReturn('Lovelace');

        return $customer;
    }
}
