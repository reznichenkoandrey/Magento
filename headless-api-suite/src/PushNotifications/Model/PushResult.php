<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Model;

/**
 * What the transport made of one send.
 *
 * `tokenIsDead` is separate from `delivered` because the two call for different actions. A transient
 * failure — a 503, a timeout — means try again later and leave the registry alone. A dead token
 * means the app was uninstalled or the token was reissued, and the row should stop being tried: FCM
 * signals this with `UNREGISTERED`, and a registry that ignores it accumulates rows that will never
 * deliver again and pays for them on every order.
 */
final class PushResult
{
    /**
     * @param bool $delivered
     * @param bool $tokenIsDead
     * @param string|null $reason For the log, never for a client.
     */
    private function __construct(
        public readonly bool $delivered,
        public readonly bool $tokenIsDead,
        public readonly ?string $reason
    ) {
    }

    /**
     * @return self
     */
    public static function delivered(): self
    {
        return new self(true, false, null);
    }

    /**
     * @param string $reason
     * @return self
     */
    public static function failed(string $reason): self
    {
        return new self(false, false, $reason);
    }

    /**
     * @param string $reason
     * @return self
     */
    public static function tokenIsDead(string $reason): self
    {
        return new self(false, true, $reason);
    }
}
