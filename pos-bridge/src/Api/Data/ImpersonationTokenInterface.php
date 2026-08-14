<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Api\Data;

/**
 * The minted credential and the moment it stops working.
 *
 * The expiry is reported so a terminal can decide for itself when to ask for a fresh token instead
 * of discovering the expiry as a 401 in the middle of a sale.
 *
 * @api
 */
interface ImpersonationTokenInterface
{
    /**
     * @return int
     */
    public function getCustomerId(): int;

    /**
     * The bearer token, to be sent as `Authorization: Bearer <token>` on the customer's behalf.
     *
     * @return string
     */
    public function getToken(): string;

    /**
     * Expiry as an ISO-8601 timestamp in UTC, read back from the token itself.
     *
     * @return string
     */
    public function getExpiresAt(): string;
}
