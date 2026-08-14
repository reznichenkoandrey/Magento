<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Api\Data;

/**
 * One candidate on the operator's screen.
 *
 * Deliberately not `CustomerInterface`. A match list is a disambiguation aid — the operator needs
 * exactly enough to pick the right person out of three similar rows and no more. Returning the full
 * customer object would put date of birth, tax/VAT number, gender and every custom attribute onto a
 * device that lives on a shop counter, for a screen that shows four fields.
 *
 * @api
 */
interface CustomerMatchInterface
{
    /**
     * @return int
     */
    public function getCustomerId(): int;

    /**
     * Account name, as stored on the customer record.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * @return string|null
     */
    public function getEmail(): ?string;

    /**
     * Name on the default billing address — the one printed on the card the shopper is holding.
     *
     * @return string|null
     */
    public function getBillingName(): ?string;

    /**
     * @return string|null
     */
    public function getBillingTelephone(): ?string;

    /**
     * @return int|null
     */
    public function getWebsiteId(): ?int;

    /**
     * The customer group, so the terminal can show which price list this shopper will be charged at.
     *
     * @return int
     */
    public function getGroupId(): int;
}
