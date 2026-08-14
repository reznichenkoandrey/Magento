<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Test\Unit\Model;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\CustomerGroupGuard\Model\Config;
use Scr1be\CustomerGroupGuard\Model\GroupResolver;
use Scr1be\CustomerGroupGuard\Model\PlaceOrderGuard;

class PlaceOrderGuardTest extends TestCase
{
    private Config&MockObject $config;
    private GroupResolver&MockObject $groupResolver;
    private LoggerInterface&MockObject $logger;
    private PlaceOrderGuard $guard;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->groupResolver = $this->createMock(GroupResolver::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->config->method('isPlaceOrderBlockEnabled')->willReturn(true);

        $this->guard = new PlaceOrderGuard($this->config, $this->groupResolver, $this->logger);
    }

    public function testRefusesACartPricedForTheOldGroup(): void
    {
        $this->groupResolver->method('resolveStoredGroupId')->willReturn(1);
        $this->logger->expects($this->once())->method('warning');

        $this->expectException(LocalizedException::class);

        $this->guard->assertGroupIsCurrent($this->quote(customerId: 15, quoteGroupId: 4));
    }

    public function testLetsAMatchingCartThrough(): void
    {
        $this->groupResolver->method('resolveStoredGroupId')->willReturn(4);
        $this->logger->expects($this->never())->method('warning');

        $this->guard->assertGroupIsCurrent($this->quote(customerId: 15, quoteGroupId: 4));
    }

    public function testStepsAsideWhenTheHardPathIsSwitchedOff(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isPlaceOrderBlockEnabled')->willReturn(false);
        $this->groupResolver->expects($this->never())->method('resolveStoredGroupId');

        $guard = new PlaceOrderGuard($config, $this->groupResolver, $this->logger);
        $guard->assertGroupIsCurrent($this->quote(customerId: 15, quoteGroupId: 4));
    }

    /**
     * A guest carries the NOT LOGGED IN group and no customer record that could contradict it.
     */
    public function testLeavesGuestsAlone(): void
    {
        $this->groupResolver->expects($this->never())->method('resolveStoredGroupId');

        $this->guard->assertGroupIsCurrent($this->quote(customerId: 0, quoteGroupId: 0));
    }

    /**
     * No group on the quote is an unanswerable question, not a mismatch.
     *
     * @dataProvider missingQuoteGroupProvider
     */
    public function testAQuoteWithoutAGroupIsNotAMismatch(mixed $stored): void
    {
        $this->groupResolver->expects($this->never())->method('resolveStoredGroupId');

        $this->guard->assertGroupIsCurrent($this->quote(customerId: 15, quoteGroupId: $stored));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function missingQuoteGroupProvider(): array
    {
        return [
            'never written' => [null],
            'blank column' => [''],
        ];
    }

    public function testAnUnreadableCustomerNeverBlocksACheckout(): void
    {
        $this->groupResolver->method('resolveStoredGroupId')->willReturn(null);
        $this->logger->expects($this->never())->method('warning');

        $this->guard->assertGroupIsCurrent($this->quote(customerId: 15, quoteGroupId: 4));
    }

    /**
     * The quote's own group column is not on the service contract, so it is read through
     * DataObject. A cart implementation that is not one has no group to judge.
     */
    public function testACartThatIsNotADataObjectIsLeftToCore(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn('15');

        $quote = $this->createMock(CartInterface::class);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getCustomer')->willReturn($customer);

        $this->groupResolver->expects($this->never())->method('resolveStoredGroupId');

        $this->guard->assertGroupIsCurrent($quote);
    }

    private function quote(int $customerId, mixed $quoteGroupId): Quote&MockObject
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($customerId === 0 ? null : (string) $customerId);

        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getCustomer')->willReturn($customer);
        $quote->method('getData')->with('customer_group_id')->willReturn($quoteGroupId);

        return $quote;
    }
}
