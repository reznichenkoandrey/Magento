<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Api;

use Scr1be\PushNotifications\Model\PushMessage;
use Scr1be\PushNotifications\Model\PushResult;

/**
 * Whatever actually delivers a push.
 *
 * An interface rather than a direct FCM call, because the default binding is a log sink. A merchant
 * who installs this module and has not yet created a Firebase project gets working plumbing and a
 * log line per notification, not a stack trace on every shipment email — and a project on APNs
 * directly, or on a queue, replaces one `preference`.
 *
 * @api
 */
interface PushTransportInterface
{
    /**
     * Deliver one message to one device token.
     *
     * Must not throw. A push is a courtesy attached to an email that has already gone out; the caller
     * is inside an order workflow and has nothing useful to do with an exception.
     *
     * @param PushMessage $message
     * @return PushResult
     */
    public function send(PushMessage $message): PushResult;
}
