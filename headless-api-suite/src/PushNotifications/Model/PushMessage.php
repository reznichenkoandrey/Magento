<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Model;

/**
 * One notification, addressed to one device.
 */
final class PushMessage
{
    /**
     * @param string $token The provider's registration token.
     * @param string $title
     * @param string $body
     * @param array<string, scalar> $data Key/value payload the app reads to decide where to
     *        navigate. FCM's `data` map is string-to-string and rejects anything else, so
     *        `FcmTransport` casts every value on the way out — which is the whole reason a
     *        caller is allowed to hand over an int or a bool here.
     */
    public function __construct(
        public readonly string $token,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = []
    ) {
    }
}
