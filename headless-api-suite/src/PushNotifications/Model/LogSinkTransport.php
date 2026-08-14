<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Model;

use Psr\Log\LoggerInterface;
use Scr1be\PushNotifications\Api\PushTransportInterface;

/**
 * The default transport: writes the notification to the log and reports success.
 *
 * This is the binding in `etc/di.xml`, and that is deliberate. A merchant installing this module has
 * plumbing to wire up — a Firebase project, a service account, an app that registers tokens — and
 * none of it is done on the afternoon `setup:upgrade` runs. Defaulting to FCM would mean every order
 * email in that window produces a stack trace in `exception.log` from a transport with no credentials.
 * Defaulting to a log sink means the whole chain is observable and inert: the plugins fire, the
 * registry is consulted, and each notification appears in `system.log` as the line FCM would have
 * been sent.
 *
 * Switching to FCM is one `preference` in a project's own di.xml, shown in the README.
 */
class LogSinkTransport implements PushTransportInterface
{
    /**
     * @param LoggerInterface $logger
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @inheritDoc
     */
    public function send(PushMessage $message): PushResult
    {
        $this->logger->info(
            sprintf(
                'Scr1be_PushNotifications [log sink]: "%s" — %s',
                $message->title,
                $message->body
            ),
            [
                // The token is truncated. A full registration token in a log file is a credential
                // that lets whoever reads the file push arbitrary notifications to that device.
                'token' => substr($message->token, 0, 12) . '…',
                'data' => $message->data,
            ]
        );

        return PushResult::delivered();
    }
}
