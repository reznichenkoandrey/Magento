<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Test\Unit\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\CustomerGroupGuard\Model\GroupResolver;

class GroupResolverTest extends TestCase
{
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private LoggerInterface&MockObject $logger;
    private GroupResolver $resolver;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->resolver = new GroupResolver($this->customerRepository, $this->logger);
    }

    public function testReadsTheGroupFromTheCustomerRecord(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn('2');

        $this->customerRepository->expects($this->once())
            ->method('getById')
            ->with(15)
            ->willReturn($customer);

        $this->assertSame(2, $this->resolver->resolveStoredGroupId(15));
    }

    public function testNeverAsksAboutAnAbsentCustomerId(): void
    {
        $this->customerRepository->expects($this->never())->method('getById');

        $this->assertNull($this->resolver->resolveStoredGroupId(0));
    }

    /**
     * Null means "unknown", and every caller reads unknown as "do nothing". A repository that
     * cannot answer is an infrastructure problem; signing a customer out or refusing their order
     * over one would make it a customer-facing problem.
     */
    public function testAnUnreadableCustomerResolvesToUnknown(): void
    {
        $this->customerRepository->method('getById')
            ->willThrowException(new NoSuchEntityException(new Phrase('gone')));

        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->resolver->resolveStoredGroupId(15));
    }

    public function testACustomerWithoutAGroupResolvesToUnknown(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(null);

        $this->customerRepository->method('getById')->willReturn($customer);

        $this->assertNull($this->resolver->resolveStoredGroupId(15));
    }
}
