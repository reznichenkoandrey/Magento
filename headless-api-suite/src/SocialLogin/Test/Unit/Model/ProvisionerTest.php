<?php
declare(strict_types=1);

namespace Scr1be\SocialLogin\Test\Unit\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\SocialLogin\Model\Provisioner;
use Scr1be\SocialLogin\Model\ResourceModel\SocialLink;
use Scr1be\SocialLogin\Model\SocialLoginException;
use Scr1be\SocialLogin\Model\Verifier\IdentityClaims;

/**
 * Five rungs, and the order between rungs 1 and 3 is a security property rather than a preference.
 */
class ProvisionerTest extends TestCase
{
    private const WEBSITE_ID = 1;
    private const STORE_ID = 2;

    private CustomerRepositoryInterface&MockObject $customerRepository;
    private CustomerInterfaceFactory&MockObject $customerFactory;
    private SocialLink&MockObject $socialLink;
    private LoggerInterface&MockObject $logger;
    private StoreInterface&MockObject $store;
    private Provisioner $provisioner;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->customerFactory = $this->createMock(CustomerInterfaceFactory::class);
        $this->socialLink = $this->createMock(SocialLink::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $group = $this->createMock(GroupInterface::class);
        $group->method('getId')->willReturn(1);
        $groupManagement = $this->createMock(GroupManagementInterface::class);
        $groupManagement->method('getDefaultGroup')->willReturn($group);

        $this->store = $this->createMock(StoreInterface::class);
        $this->store->method('getId')->willReturn(self::STORE_ID);
        $this->store->method('getWebsiteId')->willReturn(self::WEBSITE_ID);

        $this->provisioner = new Provisioner(
            $this->customerRepository,
            $this->customerFactory,
            $groupManagement,
            $this->socialLink,
            $this->logger
        );
    }

    /**
     * The link wins over everything, including a changed email. That is the difference between
     * keying on `sub` and keying on an address the provider lets people edit.
     */
    public function testAnExistingLinkResolvesWithoutTouchingTheEmail(): void
    {
        $this->socialLink->method('findCustomerId')->willReturn(77);
        $this->customerRepository->expects($this->once())->method('getById')->with(77)
            ->willReturn($this->customer(77));
        $this->customerRepository->expects($this->never())->method('get');
        $this->socialLink->expects($this->once())->method('touch');

        $customer = $this->provisioner->resolve($this->claims(email: 'changed@example.com'), $this->store);

        $this->assertSame(77, (int)$customer->getId());
    }

    public function testAVerifiedEmailMatchingAnAccountIsLinked(): void
    {
        $this->socialLink->method('findCustomerId')->willReturn(null);
        $this->customerRepository->method('get')->with('ada@example.com', self::WEBSITE_ID)
            ->willReturn($this->customer(88));
        $this->socialLink->expects($this->once())
            ->method('link')
            ->with('google', 'subject-9', 88, self::WEBSITE_ID);

        $this->assertSame(88, (int)$this->provisioner->resolve($this->claims(), $this->store)->getId());
    }

    /**
     * Without this, any provider that lets somebody assert an unverified address is a route into
     * every account on the store.
     */
    public function testAnUnverifiedEmailIsRefused(): void
    {
        $this->socialLink->method('findCustomerId')->willReturn(null);
        $this->customerRepository->expects($this->never())->method('get');

        try {
            $this->provisioner->resolve($this->claims(emailVerified: false), $this->store);
            $this->fail('Expected refusal');
        } catch (SocialLoginException $e) {
            $this->assertSame(SocialLoginException::EMAIL_UNAVAILABLE, $e->getErrorCode());
        }
    }

    public function testATokenWithNoEmailIsRefused(): void
    {
        $this->socialLink->method('findCustomerId')->willReturn(null);

        try {
            $this->provisioner->resolve($this->claims(email: null), $this->store);
            $this->fail('Expected refusal');
        } catch (SocialLoginException $e) {
            $this->assertSame(SocialLoginException::EMAIL_UNAVAILABLE, $e->getErrorCode());
        }
    }

    public function testAnUnknownIdentityGetsANewAccount(): void
    {
        $this->socialLink->method('findCustomerId')->willReturn(null);
        $this->customerRepository->method('get')
            ->willThrowException(new NoSuchEntityException(new Phrase('nope')));

        $draft = $this->customer(null);
        $this->customerFactory->method('create')->willReturn($draft);
        $this->customerRepository->expects($this->once())->method('save')->with($draft)
            ->willReturn($this->customer(99));
        $this->socialLink->expects($this->once())
            ->method('link')
            ->with('google', 'subject-9', 99, self::WEBSITE_ID);

        $this->assertSame(99, (int)$this->provisioner->resolve($this->claims(), $this->store)->getId());
    }

    /**
     * Apple does not send a name. Magento requires one.
     */
    public function testTheEmailLocalPartStandsInForAMissingFirstName(): void
    {
        $this->socialLink->method('findCustomerId')->willReturn(null);
        $this->customerRepository->method('get')
            ->willThrowException(new NoSuchEntityException(new Phrase('nope')));

        $draft = $this->customer(null);
        $this->customerFactory->method('create')->willReturn($draft);
        $this->customerRepository->method('save')->willReturn($this->customer(99));

        $this->provisioner->resolve($this->claims(firstName: null), $this->store);

        $this->assertSame('ada', $draft->getFirstname());
    }

    /**
     * Core's CustomerRepository documents InputMismatchException for a taken email but lets
     * AlreadyExistsException out of the resource model. Both mean the same thing here.
     */
    public function testAnAlreadyExistsRaceIsResolvedByLinking(): void
    {
        $this->socialLink->method('findCustomerId')->willReturn(null);

        $lookups = 0;
        $winner = $this->customer(101);
        $this->customerRepository->method('get')->willReturnCallback(
            static function () use (&$lookups, $winner) {
                if (++$lookups === 1) {
                    throw new NoSuchEntityException(new Phrase('nope'));
                }

                return $winner;
            }
        );
        $this->customerFactory->method('create')->willReturn($this->customer(null));
        $this->customerRepository->method('save')
            ->willThrowException(new AlreadyExistsException(new Phrase('taken')));
        $this->socialLink->expects($this->once())
            ->method('link')
            ->with('google', 'subject-9', 101, self::WEBSITE_ID);

        $this->assertSame(101, (int)$this->provisioner->resolve($this->claims(), $this->store)->getId());
    }

    /**
     * A link pointing at a customer that is not there means the database is not in the shape this
     * module assumes. Guessing would attach a stranger's identity to whatever turns up next.
     */
    public function testADanglingLinkIsAConflictRatherThanAFreshAccount(): void
    {
        $this->socialLink->method('findCustomerId')->willReturn(77);
        $this->customerRepository->method('getById')
            ->willThrowException(new NoSuchEntityException(new Phrase('gone')));
        $this->customerFactory->expects($this->never())->method('create');
        $this->logger->expects($this->once())->method('error');

        try {
            $this->provisioner->resolve($this->claims(), $this->store);
            $this->fail('Expected refusal');
        } catch (SocialLoginException $e) {
            $this->assertSame(SocialLoginException::ACCOUNT_CONFLICT, $e->getErrorCode());
        }
    }

    private function claims(
        ?string $email = 'ada@example.com',
        bool $emailVerified = true,
        ?string $firstName = 'Ada'
    ): IdentityClaims {
        return new IdentityClaims('google', 'subject-9', $email, $emailVerified, $firstName, 'Lovelace');
    }

    /**
     * A stateful CustomerInterface double: the create path both writes and reads it.
     *
     * Every closure below takes `$state` with an explicit `use (&$state)`, including the getters.
     * Arrow functions cannot be used here: `fn () => $state[$property]` binds `$state` **by value**
     * at the moment the closure is created, so a getter written that way would read the array as it
     * looked before any setter ran and answer null forever — which makes the assertions in this file
     * pass or fail for reasons that have nothing to do with the Provisioner.
     *
     * @param int|null $id
     * @return CustomerInterface&MockObject
     */
    private function customer(?int $id): CustomerInterface
    {
        $state = ['id' => $id];
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturnCallback(
            static function () use (&$state) {
                return $state['id'];
            }
        );

        foreach (['Firstname', 'Lastname', 'Email', 'WebsiteId', 'StoreId', 'GroupId'] as $property) {
            $customer->method('set' . $property)->willReturnCallback(
                static function ($value) use (&$state, $property, &$customer) {
                    $state[$property] = $value;

                    return $customer;
                }
            );
            $customer->method('get' . $property)->willReturnCallback(
                static function () use (&$state, $property) {
                    return $state[$property] ?? null;
                }
            );
        }

        return $customer;
    }
}
