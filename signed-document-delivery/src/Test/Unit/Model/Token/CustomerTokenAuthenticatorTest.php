<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Test\Unit\Model\Token;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Integration\Api\Data\UserToken;
use Magento\Integration\Api\Data\UserTokenDataInterface;
use Magento\Integration\Api\Exception\UserTokenException;
use Magento\Integration\Api\UserTokenReaderInterface;
use Magento\Integration\Api\UserTokenValidatorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\SignedDocumentDelivery\Model\Token\CustomerTokenAuthenticator;
use Scr1be\SignedDocumentDelivery\Model\Token\InvalidTokenException;

class CustomerTokenAuthenticatorTest extends TestCase
{
    private const BEARER = 'Bearer abcdef0123456789';

    private UserTokenReaderInterface&MockObject $reader;
    private UserTokenValidatorInterface&MockObject $validator;
    private CustomerTokenAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(UserTokenReaderInterface::class);
        $this->validator = $this->createMock(UserTokenValidatorInterface::class);
        $this->authenticator = new CustomerTokenAuthenticator($this->reader, $this->validator);
    }

    public function testAValidCustomerTokenYieldsItsCustomerId(): void
    {
        $this->reader->expects($this->once())
            ->method('read')
            ->with('abcdef0123456789')
            ->willReturn($this->userToken(UserContextInterface::USER_TYPE_CUSTOMER, 42));

        $this->assertSame(42, $this->authenticator->resolveCustomerId(self::BEARER));
    }

    public function testTheSchemeIsMatchedCaseInsensitively(): void
    {
        // Magento\Webapi\Model\Authorization\TokenUserContext lowercases it too, and clients do
        // send "bearer".
        $this->reader->method('read')->willReturn($this->userToken(UserContextInterface::USER_TYPE_CUSTOMER, 7));

        $this->assertSame(7, $this->authenticator->resolveCustomerId('bearer abcdef0123456789'));
    }

    public function testTheTokenIsValidatedAndNotJustRead(): void
    {
        // Reading a token proves it parses. Validation is what applies expiry and revocation, and
        // skipping it would keep a logged-out customer's token working until it was deleted.
        $token = $this->userToken(UserContextInterface::USER_TYPE_CUSTOMER, 42);
        $this->reader->method('read')->willReturn($token);
        $this->validator->expects($this->once())->method('validate')->with($token);

        $this->authenticator->resolveCustomerId(self::BEARER);
    }

    /**
     * @dataProvider unusableHeaders
     */
    public function testAHeaderThatIsNotACustomerBearerTokenIsRefused(
        string|false|null $header,
        string $expectedReason
    ): void {
        $this->assertRefusedBecause($expectedReason, $header);
    }

    /**
     * @return array<string, array{0: string|false|null, 1: string}>
     */
    public static function unusableHeaders(): array
    {
        return [
            // getHeader() returns false, not null, when the header is absent.
            'absent' => [false, 'no Authorization header on the download request'],
            'null' => [null, 'no Authorization header on the download request'],
            'empty string' => ['', 'no Authorization header on the download request'],
            'basic auth' => ['Basic dXNlcjpwYXNz', 'Authorization header is not a bearer token'],
            'scheme only' => ['Bearer', 'Authorization header is not a bearer token'],
            'extra whitespace splits into three' => ['Bearer  token', 'Authorization header is not a bearer token'],
            'bare token' => ['abcdef0123456789', 'Authorization header is not a bearer token'],
        ];
    }

    public function testAnUnreadableTokenIsRefused(): void
    {
        $this->reader->method('read')->willThrowException(new UserTokenException('unparseable'));

        $this->assertRefusedBecause('bearer token is unreadable, expired or revoked', self::BEARER);
    }

    public function testARevokedOrExpiredTokenIsRefused(): void
    {
        $this->reader->method('read')->willReturn($this->userToken(UserContextInterface::USER_TYPE_CUSTOMER, 42));
        $this->validator->method('validate')->willThrowException(new AuthorizationException(__('nope')));

        $this->assertRefusedBecause('bearer token is unreadable, expired or revoked', self::BEARER);
    }

    /**
     * @dataProvider nonCustomerUserTypes
     */
    public function testACredentialForSomebodyOtherThanACustomerIsRefused(int $userType): void
    {
        // An admin token is a valid credential — for the admin API. This endpoint only knows how to
        // authorize customers, and treating an admin id as a customer id would compare it against
        // sales_order.customer_id and occasionally match.
        $this->reader->method('read')->willReturn($this->userToken($userType, 42));

        $this->assertRefusedBecause('bearer token belongs to a non-customer user type', self::BEARER);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function nonCustomerUserTypes(): array
    {
        return [
            'integration' => [UserContextInterface::USER_TYPE_INTEGRATION],
            'admin' => [UserContextInterface::USER_TYPE_ADMIN],
            'guest' => [UserContextInterface::USER_TYPE_GUEST],
        ];
    }

    public function testACustomerTokenWithoutAnIdIsRefused(): void
    {
        $this->reader->method('read')->willReturn($this->userToken(UserContextInterface::USER_TYPE_CUSTOMER, null));

        $this->assertRefusedBecause('bearer token carries no customer id', self::BEARER);
    }

    private function assertRefusedBecause(string $reason, string|false|null $header): void
    {
        try {
            $this->authenticator->resolveCustomerId($header);
            $this->fail('expected the authenticator to refuse: ' . $reason);
        } catch (InvalidTokenException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    private function userToken(int $userType, ?int $userId): UserToken
    {
        $context = $this->createMock(UserContextInterface::class);
        $context->method('getUserType')->willReturn($userType);
        $context->method('getUserId')->willReturn($userId);

        return new UserToken($context, $this->createMock(UserTokenDataInterface::class));
    }
}
