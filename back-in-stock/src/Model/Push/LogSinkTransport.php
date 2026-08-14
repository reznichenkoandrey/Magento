<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model\Push;

use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Scr1be\BackInStock\Api\PushTransportInterface;

/**
 * The default transport: everything a real one would send, written to
 * `var/log/scr1be_back_in_stock_push.log` instead of to a device.
 *
 * This is not a stub. It is the implementation a shop runs while it is deciding whether it wants
 * push at all — the registry fills up, the state machine fires, the observer builds a message, and
 * the log says exactly who would have been notified and with what. Turning that into real
 * notifications is one `preference` element.
 *
 * The token is truncated in the log line. It is a credential for pushing to somebody's device, and a
 * log file is the wrong place to keep one; the first eight characters are enough to correlate a line
 * with a row in `scr1be_push_device_token` while being useless to anyone who reads the file.
 */
class LogSinkTransport implements PushTransportInterface
{
    private const TOKEN_PREFIX_LENGTH = 8;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Json $serializer
    ) {
    }

    /**
     * @inheritdoc
     */
    public function send(PushMessage $message, array $tokens): PushResult
    {
        if ($tokens === []) {
            return PushResult::nothingSent();
        }

        $this->logger->info($this->serializer->serialize([
            'title' => $message->title,
            'body' => $message->body,
            'url' => $message->url,
            'data' => $message->data,
            'tokens' => array_map(
                static fn (string $token): string => substr($token, 0, self::TOKEN_PREFIX_LENGTH) . '…',
                $tokens
            ),
        ]));

        // Nothing is ever reported invalid, which is the honest answer: a log file has no opinion
        // about whether a device still exists.
        return new PushResult(count($tokens));
    }
}
