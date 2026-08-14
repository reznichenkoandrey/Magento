<?php
declare(strict_types=1);

namespace Scr1be\CouponTicket\Model;

/**
 * Everything the template needs to draw one ticket, already decided.
 *
 * Every field here comes from the cart price rule. The widget author picks a rule and writes a
 * headline; the discount, the dates, the eligible groups and the code itself are read, never
 * retyped. A widget that lets an author type "20% off" next to a rule configured for 15% will
 * eventually be a widget that says 20% off, and the customer will be right and the store will be
 * wrong.
 */
class Ticket
{
    /**
     * @param string $code The coupon code, or an empty string when the customer may not have it.
     * @param string $discountLabel Rendered from the rule's action and amount.
     * @param string|null $fromDate Y-m-d, or null when the rule has no start date.
     * @param string|null $toDate Y-m-d, or null when the rule never expires.
     * @param bool $eligible Whether the current customer group is on the rule.
     */
    public function __construct(
        private readonly string $code,
        private readonly string $discountLabel,
        private readonly ?string $fromDate,
        private readonly ?string $toDate,
        private readonly bool $eligible
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getDiscountLabel(): string
    {
        return $this->discountLabel;
    }

    public function getFromDate(): ?string
    {
        return $this->fromDate;
    }

    public function getToDate(): ?string
    {
        return $this->toDate;
    }

    public function isEligible(): bool
    {
        return $this->eligible;
    }

    /**
     * A ticket with no code is a ticket with nothing to copy — the rule has no specific coupon, or
     * the customer is not eligible for it.
     */
    public function hasCode(): bool
    {
        return $this->code !== '';
    }
}
