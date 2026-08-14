<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Test\Unit\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\AuthenticationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Integration\Api\Data\UserToken;
use Magento\Integration\Api\Data\UserTokenDataInterface;
use Magento\Integration\Api\Exception\UserTokenException;
use Magento\Integration\Api\TokenManager;
use Magento\Integration\Api\UserTokenReaderInterface;
use Magento\Integration\Model\CustomUserContext;
use Magento\Integration\Model\CustomUserContextFactory;
use Magento\Integration\Model\UserToken\UserTokenParameters;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\PosBridge\Api\Data\ImpersonationTokenInterfaceFactory;
use Scr1be\PosBridge\Model\Config;
use Scr1be\PosBridge\Model\CustomerImpersonation;
use Scr1be\PosBridge\Model\Data\ImpersonationToken;
use Scr1be\PosBridge\Model\ImpersonationLog;

class CustomerImpersonationTest extends TestCase
{
    private const CUSTOMER_ID = 42;

    private Config&MockObject $config;
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private AuthenticationInterface&MockObject $authentication;
    private AccountManagementInterface&MockObject $accountManagement;
    private TokenManager&MockObject $tokenManager;
    private UserTokenReaderInterface&MockObject $tokenReader;
    private CustomUserContextFactory&MockObject $userContextFactory;
    private ImpersonationLog&MockObject $log;
    private CustomerImpersonation $impersonation;

    /** @var array<int, array<string, int|null>> */
    private array $mintedContexts = [];
    /** @var string[] */
    private array $tokensReadBack = [];
    private string $tokenExpiry = '2026-08-13T21:30:00+00:00';

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->authentication = $this->createMock(AuthenticationInterface::class);
        $this->accountManagement = $this->createMock(AccountManagementInterface::class);
        $this->tokenManager = $this->createMock(TokenManager::class);
        $this->tokenReader = $this->createMock(UserTokenReaderInterface::class);
        $this->userContextFactory = $this->createMock(CustomUserContextFactory::class);
        $this->log = $this->createMock(ImpersonationLog::class);

        $this->config->method('isImpersonationEnabled')->willReturn(true);
        $this->customerRepository->method('getById')->willReturn($this->customer());
        $this->authentication->method('isLocked')->willReturn(false);
        $this->accountManagement->method('getConfirmationStatus')
            ->willReturn(AccountManagementInterface::ACCOUNT_CONFIRMED);

        $this->userContextFactory->method('create')->willReturnCallback(
            function (array $data): CustomUserContext {
                $this->mintedContexts[] = $data;

                return new CustomUserContext($data['userId'], $data['userType']);
            }
        );
        $this->tokenManager->method('createUserTokenParameters')
            ->willReturn($this->createMock(UserTokenParameters::class));
        $this->tokenManager->method('create')->willReturn('a.jwt.token');
        $this->tokenReader->method('read')->willReturnCallback(
            function (string $token): UserToken {
                $this->tokensReadBack[] = $token;

                return $this->readToken($this->tokenExpiry);
            }
        );

        $tokenFactory = $this->createMock(ImpersonationTokenInterfaceFactory::class);
        $tokenFactory->method('create')->willReturnCallback(
            static fn (array $data): ImpersonationToken => new ImpersonationToken(
                $data['customerId'],
                $data['token'],
                $data['expiresAt']
            )
        );

        $this->impersonation = new CustomerImpersonation(
            $this->config,
            $this->customerRepository,
            $this->authentication,
            $this->accountManagement,
            $this->tokenManager,
            $this->tokenReader,
            $this->userContextFactory,
            $tokenFactory,
            $this->log
        );
    }

    /**
     * The context handed to core's token manager is what decides whose token this is. A wrong user
     * type here would mint an admin credential from a customer id.
     */
    public function testTheTokenIsMintedForThatCustomerAsACustomer(): void
    {
        $result = $this->impersonation->issueToken(self::CUSTOMER_ID);

        $this->assertSame(
            [['userId' => self::CUSTOMER_ID, 'userType' => UserContextInterface::USER_TYPE_CUSTOMER]],
            $this->mintedContexts
        );
        $this->assertSame(self::CUSTOMER_ID, $result->getCustomerId());
        $this->assertSame('a.jwt.token', $result->getToken());
    }

    /**
     * Read back out of the token, not recomputed from the TTL setting — a second copy of core's
     * expiry rule would drift and report an expiry the token does not have.
     */
    public function testTheExpiryIsReportedInUtcFromTheTokenItself(): void
    {
        $this->tokenExpiry = '2026-08-13T23:30:00+02:00';

        $result = $this->impersonation->issueToken(self::CUSTOMER_ID);

        $this->assertSame(['a.jwt.token'], $this->tokensReadBack);
        $this->assertSame('2026-08-13T21:30:00+00:00', $result->getExpiresAt());
    }

    public function testASwitchedOffEndpointMintsNothing(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isImpersonationEnabled')->willReturn(false);

        $impersonation = $this->withConfig($config);

        $this->customerRepository->expects($this->never())->method('getById');
        $this->tokenManager->expects($this->never())->method('create');
        $this->log->expects($this->once())->method('refused');
        $this->expectException(LocalizedException::class);

        $impersonation->issueToken(self::CUSTOMER_ID);
    }

    public function testAnUnknownCustomerPropagatesAsNotFound(): void
    {
        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')
            ->willThrowException(new NoSuchEntityException(new Phrase('no customer')));

        $impersonation = new CustomerImpersonation(
            $this->config,
            $customerRepository,
            $this->authentication,
            $this->accountManagement,
            $this->tokenManager,
            $this->tokenReader,
            $this->userContextFactory,
            $this->createMock(ImpersonationTokenInterfaceFactory::class),
            $this->log
        );

        $this->tokenManager->expects($this->never())->method('create');
        $this->expectException(NoSuchEntityException::class);

        $impersonation->issueToken(self::CUSTOMER_ID);
    }

    /**
     * The endpoint skips the password, so it has to enforce what a password check would have. A
     * locked account reachable through the till is a documented way around the lockout policy.
     */
    public function testALockedAccountIsRefused(): void
    {
        $authentication = $this->createMock(AuthenticationInterface::class);
        $authentication->method('isLocked')->willReturn(true);

        $impersonation = new CustomerImpersonation(
            $this->config,
            $this->customerRepository,
            $authentication,
            $this->accountManagement,
            $this->tokenManager,
            $this->tokenReader,
            $this->userContextFactory,
            $this->createMock(ImpersonationTokenInterfaceFactory::class),
            $this->log
        );

        $this->tokenManager->expects($this->never())->method('create');
        $this->log->expects($this->once())->method('refused');
        $this->expectException(LocalizedException::class);

        $impersonation->issueToken(self::CUSTOMER_ID);
    }

    public function testAnAccountStillAwaitingConfirmationIsRefused(): void
    {
        $accountManagement = $this->createMock(AccountManagementInterface::class);
        $accountManagement->method('getConfirmationStatus')
            ->willReturn(AccountManagementInterface::ACCOUNT_CONFIRMATION_REQUIRED);

        $impersonation = new CustomerImpersonation(
            $this->config,
            $this->customerRepository,
            $this->authentication,
            $accountManagement,
            $this->tokenManager,
            $this->tokenReader,
            $this->userContextFactory,
            $this->createMock(ImpersonationTokenInterfaceFactory::class),
            $this->log
        );

        $this->tokenManager->expects($this->never())->method('create');
        $this->expectException(LocalizedException::class);

        $impersonation->issueToken(self::CUSTOMER_ID);
    }

    /**
     * An installation that does not require confirmation reports a third status, and it is not a
     * refusal.
     */
    public function testAnInstallationWithoutConfirmationIsNotTreatedAsUnconfirmed(): void
    {
        $accountManagement = $this->createMock(AccountManagementInterface::class);
        $accountManagement->method('getConfirmationStatus')
            ->willReturn(AccountManagementInterface::ACCOUNT_CONFIRMATION_NOT_REQUIRED);

        $impersonation = new CustomerImpersonation(
            $this->config,
            $this->customerRepository,
            $this->authentication,
            $accountManagement,
            $this->tokenManager,
            $this->tokenReader,
            $this->userContextFactory,
            $this->tokenFactory(),
            $this->log
        );

        $this->assertSame('a.jwt.token', $impersonation->issueToken(self::CUSTOMER_ID)->getToken());
    }

    /**
     * A token nobody can describe is not handed out. The alternative — returning it with a guessed
     * expiry — is a credential whose lifetime the terminal would be wrong about.
     */
    public function testATokenThatCannotBeReadBackIsNotReturned(): void
    {
        $tokenReader = $this->createMock(UserTokenReaderInterface::class);
        $tokenReader->method('read')->willThrowException(new UserTokenException('unreadable'));

        $impersonation = new CustomerImpersonation(
            $this->config,
            $this->customerRepository,
            $this->authentication,
            $this->accountManagement,
            $this->tokenManager,
            $tokenReader,
            $this->userContextFactory,
            $this->tokenFactory(),
            $this->log
        );

        $this->log->expects($this->once())->method('refused');
        $this->log->expects($this->never())->method('issued');
        $this->expectException(LocalizedException::class);

        $impersonation->issueToken(self::CUSTOMER_ID);
    }

    public function testASuccessfulMintIsRecorded(): void
    {
        $this->log->expects($this->once())
            ->method('issued')
            ->with(self::CUSTOMER_ID, '2026-08-13T21:30:00+00:00');

        $this->impersonation->issueToken(self::CUSTOMER_ID);
    }

    private function withConfig(Config&MockObject $config): CustomerImpersonation
    {
        return new CustomerImpersonation(
            $config,
            $this->customerRepository,
            $this->authentication,
            $this->accountManagement,
            $this->tokenManager,
            $this->tokenReader,
            $this->userContextFactory,
            $this->tokenFactory(),
            $this->log
        );
    }

    private function tokenFactory(): ImpersonationTokenInterfaceFactory&MockObject
    {
        $factory = $this->createMock(ImpersonationTokenInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $data): ImpersonationToken => new ImpersonationToken(
                $data['customerId'],
                $data['token'],
                $data['expiresAt']
            )
        );

        return $factory;
    }

    private function customer(): CustomerInterface&MockObject
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(self::CUSTOMER_ID);

        return $customer;
    }

    private function readToken(string $expiresAt): UserToken
    {
        $data = $this->createMock(UserTokenDataInterface::class);
        $data->method('getExpires')->willReturn(new \DateTimeImmutable($expiresAt));

        return new UserToken(
            new CustomUserContext(self::CUSTOMER_ID, UserContextInterface::USER_TYPE_CUSTOMER),
            $data
        );
    }
}
