<?php
declare(strict_types=1);

namespace Scr1be\CouponTicket\Test\Unit\Model;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\RuleFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CouponTicket\Model\Eligibility;
use Scr1be\CouponTicket\Model\TicketReader;

class TicketReaderTest extends TestCase
{
    public function testAWidgetWithNoRuleRendersNothing(): void
    {
        $this->assertNull($this->reader($this->rule(0))->read(0));
    }

    public function testADeletedRuleRendersNothingRatherThanAnError(): void
    {
        // A storefront that shouts about a misconfigured widget shows customers a stack trace where
        // a discount should be.
        $this->assertNull($this->reader($this->rule(0))->read(7));
    }

    public function testAnInactiveRuleRendersNothing(): void
    {
        $this->assertNull($this->reader($this->rule(7, isActive: false))->read(7));
    }

    public function testAPercentRuleIsDescribedAsAPercentage(): void
    {
        $ticket = $this->reader($this->rule(7, action: Rule::BY_PERCENT_ACTION, amount: 20.0))->read(7);

        $this->assertSame('20% off', $ticket->getDiscountLabel());
    }

    public function testAPercentageIsNotPrintedWithFourDecimalPlaces(): void
    {
        // discount_amount is a DECIMAL column, so 20 comes back as "20.0000".
        $ticket = $this->reader($this->rule(7, action: Rule::BY_PERCENT_ACTION, amount: 12.5))->read(7);

        $this->assertSame('12.5% off', $ticket->getDiscountLabel());
    }

    public function testAFixedCartDiscountIsFormattedAsMoney(): void
    {
        $ticket = $this->reader($this->rule(7, action: Rule::CART_FIXED_ACTION, amount: 10.0))->read(7);

        $this->assertSame('$10.00 off', $ticket->getDiscountLabel());
    }

    public function testAToPercentRuleIsNotDescribedAsADiscountOff(): void
    {
        // to_percent sets the price *to* a percentage. Printing "80% off" for it would advertise
        // the opposite of the rule.
        $ticket = $this->reader($this->rule(7, action: Rule::TO_PERCENT_ACTION, amount: 80.0))->read(7);

        $this->assertSame('Pay 80% of the price', $ticket->getDiscountLabel());
    }

    public function testTheCodeComesFromThePrimaryCoupon(): void
    {
        $ticket = $this->reader($this->rule(7), couponCode: 'SPRING20')->read(7);

        $this->assertSame('SPRING20', $ticket->getCode());
        $this->assertTrue($ticket->hasCode());
    }

    public function testARuleWithNoCouponHasNothingToCopy(): void
    {
        // NO_COUPON rules apply automatically; there is no code to print on a ticket.
        $ticket = $this->reader(
            $this->rule(7, couponType: Rule::COUPON_TYPE_NO_COUPON),
            couponCode: 'NEVER-READ'
        )->read(7);

        $this->assertSame('', $ticket->getCode());
        $this->assertFalse($ticket->hasCode());
    }

    public function testTheCodeIsWithheldFromAnIneligibleCustomer(): void
    {
        // Otherwise it is sitting in the markup, and a code that is visible and then rejected at
        // checkout is worse for the customer than no code at all.
        $ticket = $this->reader($this->rule(7), couponCode: 'SPRING20', eligible: false)->read(7);

        $this->assertFalse($ticket->isEligible());
        $this->assertSame('', $ticket->getCode());
    }

    public function testTheDiscountIsStillShownToAnIneligibleCustomer(): void
    {
        $ticket = $this->reader(
            $this->rule(7, action: Rule::BY_PERCENT_ACTION, amount: 20.0),
            eligible: false
        )->read(7);

        $this->assertSame('20% off', $ticket->getDiscountLabel());
    }

    public function testDatesAreTrimmedToTheDay(): void
    {
        $ticket = $this->reader($this->rule(7, toDate: '2026-12-31 00:00:00'))->read(7);

        $this->assertSame('2026-12-31', $ticket->getToDate());
    }

    public function testARuleWithNoEndDateReportsNone(): void
    {
        $this->assertNull($this->reader($this->rule(7, toDate: ''))->read(7)->getToDate());
    }

    private function reader(
        Rule&MockObject $rule,
        string $couponCode = 'CODE',
        bool $eligible = true
    ): TicketReader {
        $ruleFactory = $this->createMock(RuleFactory::class);
        $ruleFactory->method('create')->willReturn($rule);

        $coupon = $this->createMock(Coupon::class);
        $coupon->method('getCode')->willReturn($couponCode);

        $couponFactory = $this->createMock(CouponFactory::class);
        $couponFactory->method('create')->willReturn($coupon);

        $eligibility = $this->createMock(Eligibility::class);
        $eligibility->method('isEligible')->willReturn($eligible);

        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('format')->willReturnCallback(
            static fn (float $amount): string => '$' . number_format($amount, 2)
        );

        return new TicketReader(
            $ruleFactory,
            $this->createMock(RuleResource::class),
            $couponFactory,
            $eligibility,
            $priceCurrency
        );
    }

    /**
     * `is_active`, `coupon_type`, `discount_amount` and `discount_step` are stubbed through
     * `getData()` because `Magento\SalesRule\Model\Rule` declares no getters for them — they are
     * `DataObject::__call()` magic, which a mock has no method to configure.
     */
    private function rule(
        int $id,
        bool $isActive = true,
        string $action = Rule::BY_PERCENT_ACTION,
        float $amount = 10.0,
        int $couponType = Rule::COUPON_TYPE_SPECIFIC,
        string $toDate = ''
    ): Rule&MockObject {
        $rule = $this->createMock(Rule::class);
        $rule->method('getId')->willReturn($id ?: null);
        $rule->method('getData')->willReturnMap([
            ['is_active', null, $isActive],
            ['coupon_type', null, $couponType],
            ['discount_amount', null, $amount],
            ['discount_step', null, 0],
        ]);
        $rule->method('getSimpleAction')->willReturn($action);
        $rule->method('getCustomerGroupIds')->willReturn([1]);
        $rule->method('getFromDate')->willReturn('');
        $rule->method('getToDate')->willReturn($toDate);

        return $rule;
    }
}
