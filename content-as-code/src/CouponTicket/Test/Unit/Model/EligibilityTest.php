<?php
declare(strict_types=1);

namespace Scr1be\CouponTicket\Test\Unit\Model;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Customer\Model\GroupManagement;
use Magento\Framework\App\Http\Context as HttpContext;
use PHPUnit\Framework\TestCase;
use Scr1be\CouponTicket\Model\Eligibility;

class EligibilityTest extends TestCase
{
    public function testTheGroupComesFromTheHttpContext(): void
    {
        // Not the customer session: a session read inside a cacheable block hands the customer who
        // warmed the cache their answer to everybody else.
        $this->assertSame(3, $this->eligibility(3)->getCurrentGroupId());
    }

    public function testAContextWithNoGroupIsTreatedAsAGuest(): void
    {
        // Happens wherever Magento\Customer\Model\App\Action\ContextPlugin did not run — a console
        // command rendering a block, for instance.
        $this->assertSame(
            GroupManagement::NOT_LOGGED_IN_ID,
            $this->eligibility(null)->getCurrentGroupId()
        );
    }

    public function testTheContextValueIsCastBecauseThePluginWritesItAsAString(): void
    {
        // ContextPlugin::beforeExecute() sets (string)$this->customerSession->getCustomerGroupId().
        $this->assertSame(2, $this->eligibility('2')->getCurrentGroupId());
    }

    public function testAGroupOnTheRuleIsEligible(): void
    {
        $this->assertTrue($this->eligibility(3)->isEligible([1, 3]));
    }

    public function testAGroupNotOnTheRuleIsNot(): void
    {
        $this->assertFalse($this->eligibility(2)->isEligible([1, 3]));
    }

    public function testGroupIdsArriveAsStringsFromTheDatabaseAndStillMatch(): void
    {
        // salesrule_customer_group rows come back as strings; a strict in_array against ints would
        // hide the coupon from every customer.
        $this->assertTrue($this->eligibility(3)->isEligible(['1', '3']));
    }

    public function testGuestsMatchGroupZero(): void
    {
        $this->assertTrue($this->eligibility(null)->isEligible([0, 1]));
    }

    public function testARuleWithNoGroupsAppliesToNobody(): void
    {
        // Core's admin form makes the field required, so an empty list means the rule was written
        // by something else. "Show the coupon to everyone" is the wrong way to resolve that.
        $this->assertFalse($this->eligibility(1)->isEligible([]));
    }

    private function eligibility(string|int|null $contextGroup): Eligibility
    {
        $httpContext = $this->createMock(HttpContext::class);
        $httpContext->method('getValue')
            ->with(CustomerContext::CONTEXT_GROUP)
            ->willReturn($contextGroup);

        return new Eligibility($httpContext);
    }
}
