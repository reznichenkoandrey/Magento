<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Api;

use Scr1be\PosBridge\Api\Data\ImpersonationTokenInterface;

/**
 * Turns "this is the customer" into a credential the terminal can act with.
 *
 * The result is an ordinary customer token — the same kind `POST /V1/integration/customer/token`
 * hands a shopper who typed their password. Everything downstream (cart, addresses, orders, loyalty)
 * therefore works without a single further change, which is the entire reason the endpoint mints a
 * token instead of exposing a parallel set of act-as-customer operations.
 *
 * @api
 */
interface CustomerImpersonationInterface
{
    /**
     * Issue a customer token for the given customer.
     *
     * @param int $customerId
     * @return \Scr1be\PosBridge\Api\Data\ImpersonationTokenInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException When no such customer exists.
     * @throws \Magento\Framework\Exception\LocalizedException When the account may not be acted for,
     *         or the bridge is switched off.
     */
    public function issueToken(int $customerId): ImpersonationTokenInterface;
}
