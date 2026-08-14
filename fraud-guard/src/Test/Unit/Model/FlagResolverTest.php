<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Test\Unit\Model;

use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\FraudGuard\Model\FlagResolver;
use Scr1be\FraudGuard\Model\GuardLog;

class FlagResolverTest extends TestCase
{
    private CustomerRegistry&MockObject $customerRegistry;
    private GuardLog&MockObject $log;
    private FlagResolver $resolver;

    protected function setUp(): void
    {
        $this->customerRegistry = $this->createMock(CustomerRegistry::class);
        $this->log = $this->createMock(GuardLog::class);
        $this->resolver = new FlagResolver($this->customerRegistry, $this->log);
    }

    /**
     * @dataProvider storedValueProvider
     */
    public function testReadsTheRawAttributeValue(mixed $stored, bool $expected): void
    {
        $this->customerRegistry->method('retrieve')->with(42)->willReturn($this->customer($stored));

        $this->assertSame($expected, $this->resolver->isFlagged(42));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function storedValueProvider(): array
    {
        return [
            'flagged' => ['1', true],
            'explicitly cleared' => ['0', false],
            'never set' => [null, false],
            'integer form' => [1, true],
        ];
    }

    public function testGuestQuotesNeverReachTheRegistry(): void
    {
        $this->customerRegistry->expects($this->never())->method('retrieve');

        $this->assertFalse($this->resolver->isFlagged(0));
    }

    public function testResolvesOncePerCustomerPerRequest(): void
    {
        // placeOrder() and submit() can both run in one request; the answer cannot change between
        // them, so the registry must be asked exactly once.
        $this->customerRegistry->expects($this->once())
            ->method('retrieve')
            ->willReturn($this->customer('1'));

        $this->assertTrue($this->resolver->isFlagged(42));
        $this->assertTrue($this->resolver->isFlagged(42));
    }

    public function testStateResetClearsTheMemo(): void
    {
        $this->customerRegistry->expects($this->exactly(2))
            ->method('retrieve')
            ->willReturn($this->customer('1'));

        $this->resolver->isFlagged(42);
        $this->resolver->_resetState();
        $this->resolver->isFlagged(42);
    }

    public function testAMissingCustomerIsNotAnIncident(): void
    {
        $this->customerRegistry->method('retrieve')
            ->willThrowException(new NoSuchEntityException(new Phrase('gone')));
        $this->log->expects($this->never())->method('lookupFailed');

        $this->assertFalse($this->resolver->isFlagged(42));
    }

    public function testFailsOpenAndLogsWhenTheLookupBreaks(): void
    {
        $failure = new \RuntimeException('connection lost');
        $this->customerRegistry->method('retrieve')->willThrowException($failure);
        $this->log->expects($this->once())->method('lookupFailed')->with(42, $failure);

        $this->assertFalse($this->resolver->isFlagged(42));
    }

    private function customer(mixed $flagValue): Customer&MockObject
    {
        $customer = $this->createMock(Customer::class);
        $customer->method('getData')->with(FlagResolver::ATTRIBUTE_CODE)->willReturn($flagValue);

        return $customer;
    }
}
