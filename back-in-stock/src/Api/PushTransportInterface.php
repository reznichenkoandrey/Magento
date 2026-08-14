<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Api;

use Scr1be\BackInStock\Model\Push\PushMessage;
use Scr1be\BackInStock\Model\Push\PushResult;

/**
 * The module's one extension point, and the reason the push channel is genuinely optional.
 *
 * The default implementation writes to a log file, so a demo — or a staging environment, or a CI
 * run — exercises the whole path from restock to notification without a Firebase project, a service
 * account or an outbound HTTPS call. Pointing this interface at
 * `Scr1be\BackInStock\Model\Push\Fcm\FcmTransport` in di.xml is the only change needed to make the
 * same code send real notifications, and pointing it at a queue publisher is the change a busy shop
 * would make instead.
 *
 * Implementations must not throw. A transport that raises on a network blip takes down the
 * `product_alert` mail run with it, and the customer's email — the thing they actually asked for —
 * is the more important of the two channels.
 */
interface PushTransportInterface
{
    /**
     * @param string[] $tokens Device tokens, already filtered to the active ones.
     */
    public function send(PushMessage $message, array $tokens): PushResult;
}
