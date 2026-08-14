<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Model\Fcm;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Scr1be\PushNotifications\Api\PushTransportInterface;
use Scr1be\PushNotifications\Model\Config;
use Scr1be\PushNotifications\Model\PushMessage;
use Scr1be\PushNotifications\Model\PushResult;

/**
 * FCM HTTP v1.
 *
 * The interesting part is the error handling, not the send. FCM answers a dead token with HTTP 404
 * and `errorCode: UNREGISTERED` in the `error.details` array — the app was uninstalled, or the token
 * was reissued and this one superseded. That is a permanent condition and the only correct response
 * is to stop trying, which is why it comes back as a distinct `PushResult` rather than as a generic
 * failure. A registry that treats it as transient accumulates tokens it will retry on every order
 * for the rest of the installation's life.
 *
 * HTTP 400 with `INVALID_ARGUMENT` on the token field means the same thing for a different reason —
 * the string is not a token at all — and is treated the same way.
 *
 * @see https://firebase.google.com/docs/reference/fcm/rest/v1/ErrorCode
 */
class FcmTransport implements PushTransportInterface
{
    private const SEND_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    /**
     * FCM error codes that mean "never try this token again".
     */
    private const PERMANENT_TOKEN_ERRORS = ['UNREGISTERED', 'INVALID_ARGUMENT'];

    private const HTTP_TIMEOUT = 10;
    private const HTTP_OK = 200;

    /**
     * @param Config $config
     * @param AccessTokenProvider $accessTokenProvider
     * @param CurlFactory $curlFactory
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly AccessTokenProvider $accessTokenProvider,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function send(PushMessage $message): PushResult
    {
        try {
            $account = ServiceAccount::fromJson($this->config->getServiceAccountKey(), $this->json);
            $accessToken = $this->accessTokenProvider->getToken($account);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Scr1be_PushNotifications: FCM is not usable: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return PushResult::failed('transport is not configured');
        }

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::HTTP_TIMEOUT);
            $curl->addHeader('Authorization', 'Bearer ' . $accessToken);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->post(
                sprintf(self::SEND_ENDPOINT, $account->projectId),
                (string)$this->json->serialize($this->payload($message))
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Scr1be_PushNotifications: FCM request failed: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return PushResult::failed('the request could not be made');
        }

        return $this->interpret($curl->getStatus(), $curl->getBody());
    }

    /**
     * @param PushMessage $message
     * @return array<string, mixed>
     */
    private function payload(PushMessage $message): array
    {
        return [
            'message' => [
                'token' => $message->token,
                'notification' => [
                    'title' => $message->title,
                    'body' => $message->body,
                ],
                // FCM's `data` map is string-to-string; anything else is rejected with
                // INVALID_ARGUMENT, which this class would then read as a dead token.
                'data' => array_map(static fn ($value): string => (string)$value, $message->data),
            ],
        ];
    }

    /**
     * @param int $status
     * @param string $body
     * @return PushResult
     */
    private function interpret(int $status, string $body): PushResult
    {
        if ($status === self::HTTP_OK) {
            return PushResult::delivered();
        }

        $errorCode = $this->errorCode($body);

        if ($errorCode !== null && in_array($errorCode, self::PERMANENT_TOKEN_ERRORS, true)) {
            return PushResult::tokenIsDead($errorCode);
        }

        $reason = sprintf('HTTP %d%s', $status, $errorCode === null ? '' : ' (' . $errorCode . ')');
        $this->logger->warning('Scr1be_PushNotifications: FCM refused a message — ' . $reason);

        return PushResult::failed($reason);
    }

    /**
     * Dig the FCM error code out of the error envelope.
     *
     * The code is read from `error.details[]` rather than from the top-level `error.status`, because
     * `error.status` for a dead token is `NOT_FOUND` — the same value a wrong project id produces.
     * Only the detail entry carries the specific `errorCode`.
     *
     * The details array is heterogeneous: Google mixes its own `google.rpc.*` entries in with the
     * FCM-specific one. Rather than match on the `@type` URI, which is a string Google is free to
     * re-version, this takes the first entry that actually carries an `errorCode` — the field being
     * looked for is its own discriminator, and no `google.rpc.*` detail defines one.
     *
     * @param string $body
     * @return string|null
     */
    private function errorCode(string $body): ?string
    {
        try {
            $decoded = $this->json->unserialize($body);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        foreach ($decoded['error']['details'] ?? [] as $detail) {
            if (is_array($detail) && isset($detail['errorCode']) && is_string($detail['errorCode'])) {
                return $detail['errorCode'];
            }
        }

        return null;
    }
}
