<?php
declare(strict_types=1);

namespace Scr1be\CouponTicket\Model;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\RuleFactory;

/**
 * Reads one cart price rule and turns it into a {@see Ticket}.
 *
 * ### Why the model and not `RuleRepositoryInterface`
 *
 * The repository returns a `Magento\SalesRule\Api\Data\RuleInterface`, which has no way to reach the
 * coupon code — and the two layers do not even agree on how to spell a coupon type: the data
 * interface declares `COUPON_TYPE_SPECIFIC_COUPON = 'SPECIFIC_COUPON'` while the model, and the
 * `coupon_type` column behind it, use `Rule::COUPON_TYPE_SPECIFIC = 2`. The code lives on
 * `Magento\SalesRule\Model\Coupon`, reachable through `Rule::getPrimaryCoupon()`, which loads the
 * `is_primary` row for the rule. So the model it is, and the integer constants with it.
 *
 * ### What a missing rule does
 *
 * Nothing visible. A widget pointing at a rule that was deleted returns null and the block renders
 * an empty string. A storefront that shouts about a misconfigured widget is a storefront that
 * shows customers a stack trace where a discount should be.
 *
 * ### Why some fields are read with `getData()`
 *
 * `Magento\SalesRule\Model\Rule` declares `getSimpleAction()`, `getFromDate()`, `getToDate()` and
 * `getCustomerGroupIds()` — the last one lazily loads the association table, so it has to be the
 * method. It declares no `getIsActive()`, `getCouponType()`, `getDiscountAmount()` or
 * `getDiscountStep()`; those are `DataObject`'s magic getters resolving to column names, and calling
 * them through `getData()` says so out loud instead of relying on `__call`.
 */
class TicketReader
{
    private const FIELD_IS_ACTIVE = 'is_active';
    private const FIELD_COUPON_TYPE = 'coupon_type';
    private const FIELD_DISCOUNT_AMOUNT = 'discount_amount';
    private const FIELD_DISCOUNT_STEP = 'discount_step';

    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private readonly RuleResource $ruleResource,
        private readonly CouponFactory $couponFactory,
        private readonly Eligibility $eligibility,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    public function read(int $ruleId): ?Ticket
    {
        if ($ruleId <= 0) {
            return null;
        }

        $rule = $this->ruleFactory->create();
        $this->ruleResource->load($rule, $ruleId);

        if (!$rule->getId() || !$rule->getData(self::FIELD_IS_ACTIVE)) {
            return null;
        }

        $eligible = $this->eligibility->isEligible((array)$rule->getCustomerGroupIds());

        return new Ticket(
            // The code is withheld from customers the rule does not apply to. It would be visible
            // in the markup otherwise, and a code that is visible and rejected at checkout is worse
            // for the customer than no code at all.
            $eligible ? $this->readCode($rule) : '',
            $this->describeDiscount($rule),
            $this->normalizeDate($rule->getFromDate()),
            $this->normalizeDate($rule->getToDate()),
            $eligible
        );
    }

    private function readCode(Rule $rule): string
    {
        if ((int)$rule->getData(self::FIELD_COUPON_TYPE) !== Rule::COUPON_TYPE_SPECIFIC) {
            // NO_COUPON rules apply automatically and AUTO rules generate a code per customer;
            // neither has one code to print on a ticket.
            return '';
        }

        $coupon = $this->couponFactory->create();
        $coupon->loadPrimaryByRule((int)$rule->getId());

        return (string)$coupon->getCode();
    }

    /**
     * The four discount actions core ships, spelled out. `to_percent` and `to_fixed` set the price
     * *to* a value rather than reducing by one, so they read differently on purpose — an author who
     * picks one and gets "20% off" printed would be advertising the opposite of the rule.
     */
    private function describeDiscount(Rule $rule): string
    {
        $amount = (float)$rule->getData(self::FIELD_DISCOUNT_AMOUNT);

        // A literal `%` is written as itself. `Magento\Framework\Phrase\Renderer\Placeholder::render()`
        // substitutes `%1`, `%2`… with `strtr()` and never touches the rest of the string, so the
        // sprintf habit of doubling the sign would print "20%% off".
        return match ((string)$rule->getSimpleAction()) {
            Rule::BY_PERCENT_ACTION => (string)__('%1% off', $this->formatNumber($amount)),
            Rule::TO_PERCENT_ACTION => (string)__('Pay %1% of the price', $this->formatNumber($amount)),
            Rule::BY_FIXED_ACTION,
            Rule::CART_FIXED_ACTION => (string)__('%1 off', $this->priceCurrency->format($amount, false)),
            Rule::TO_FIXED_ACTION => (string)__('Pay %1', $this->priceCurrency->format($amount, false)),
            Rule::BUY_X_GET_Y_ACTION => (string)__(
                'Buy %1, get %2 free',
                (int)$rule->getData(self::FIELD_DISCOUNT_STEP),
                $this->formatNumber($amount)
            ),
            default => '',
        };
    }

    /**
     * Percentages are typed as decimals in the admin and stored as such; `20.0000` on a ticket
     * looks like a bug even though it is exactly what the rule says.
     */
    private function formatNumber(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    private function normalizeDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        // `from_date` / `to_date` are DATE columns; anything after the day is noise on a ticket.
        return substr($date, 0, 10);
    }
}
