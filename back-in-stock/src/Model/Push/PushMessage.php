<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model\Push;

/**
 * One notification, in the vocabulary every push service shares.
 *
 * Title, body, a link and a small data bag is the intersection of FCM, APNs and the Web Push
 * protocol — deliberately, so that swapping the transport is a `preference` in di.xml rather than a
 * rewrite of whatever builds the message.
 */
final class PushMessage
{
    /**
     * @param array<string, string> $data Key/value pairs the client app receives alongside the
     *        notification. FCM requires both sides to be strings, so this is typed that way here
     *        rather than being coerced three layers down.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly array $data = []
    ) {
    }
}
