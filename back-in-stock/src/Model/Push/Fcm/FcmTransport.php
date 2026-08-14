<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model\Push\Fcm;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Scr1be\BackInStock\Api\PushTransportInterface;
use Scr1be\BackInStock\Model\Config;
use Scr1be\BackInStock\Model\Push\PushMessage;
use Scr1be\BackInStock\Model\Push\PushResult;

/**
 * Firebase Cloud Messaging, HTTP v1.
 *
 * **One request per token, on purpose.** HTTP v1 removed the legacy API's `registration_ids` array;
 * the multicast replacement is a batch endpoint whose payload is a hand-assembled multipart
 * document, and the topic/condition alternatives require the *client* to have subscribed to a topic
 * this server does not control. A loop over a handful of a single customer's devices is the correct
 * shape for this workload — a customer has two or three, not two thousand.
 *
 * **Self-healing.** FCM answers a token that no longer belongs to a live installation with
 * `UNREGISTERED` (HTTP 404), and one that was never a token with `INVALID_ARGUMENT` (HTTP 400).
 * Those are the two permanent refusals, and they are reported back through `PushResult` so the
 * registry can retire the row. Everything else — a 429, a 503, a timeout — is transient by
 * definition and must not deactivate anything: retiring a token because Google had a bad minute
 * would quietly unsubscribe real customers.
 *
 * **It never throws.** The caller is an observer inside the alert mail run. See
 * `PushTransportInterface` for why that matters.
 */
class FcmTransport implements PushTransportInterface
{
    private const ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private const TIMEOUT = 10;

    /** FCM's two permanent refusals, by the `error.status` it returns with them. */
    private const PERMANENT_ERRORS = ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'];

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly AccessTokenProvider $accessTokenProvider,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly Json $serializer,
        private readonly LoggerInterface $logger
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

        try {
            $websiteId = (int)$this->storeManager->getWebsite()->getId();
            $projectId = $this->resolveProjectId($websiteId);
            $accessToken = $this->accessTokenProvider->getToken($this->readServiceAccount($websiteId));
        } catch (\Exception $exception) {
            // A misconfiguration is not a dead device: no token is reported invalid here.
            $this->logger->error('FCM push is configured but unusable: ' . $exception->getMessage());

            return new PushResult(0, [], [$exception->getMessage()]);
        }

        $delivered = 0;
        $invalid = [];
        $errors = [];

        foreach ($tokens as $token) {
            [$ok, $isPermanent, $error] = $this->sendOne($projectId, $accessToken, $message, $token);

            if ($ok) {
                $delivered++;
                continue;
            }

            if ($isPermanent) {
                $invalid[] = $token;
            }

            $errors[] = $error;
        }

        return new PushResult($delivered, $invalid, $errors);
    }

    /**
     * @return array{0: bool, 1: bool, 2: string} Delivered, permanently refused, and the error line.
     */
    private function sendOne(string $projectId, string $accessToken, PushMessage $message, string $token): array
    {
        $client = $this->curlFactory->create();
        $client->setTimeout(self::TIMEOUT);
        $client->addHeader('Authorization', 'Bearer ' . $accessToken);
        $client->addHeader('Content-Type', 'application/json');

        try {
            $client->post(
                sprintf(self::ENDPOINT, $projectId),
                $this->serializer->serialize($this->buildPayload($message, $token))
            );
        } catch (\Exception $exception) {
            return [false, false, 'transport: ' . $exception->getMessage()];
        }

        $status = $client->getStatus();

        if ($status >= 200 && $status < 300) {
            return [true, false, ''];
        }

        $error = $this->readErrorStatus($client->getBody());

        return [
            false,
            in_array($error, self::PERMANENT_ERRORS, true),
            sprintf('HTTP %d %s', $status, $error !== '' ? $error : 'unknown'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(PushMessage $message, string $token): array
    {
        return [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $message->title,
                    'body' => $message->body,
                ],
                // The click target belongs under `webpush` rather than in `data`: a browser that
                // receives it through the FCM service worker opens the link without the site having
                // to ship a notification click handler of its own.
                'webpush' => [
                    'fcm_options' => ['link' => $message->url],
                ],
                'data' => $message->data,
            ],
        ];
    }

    /**
     * FCM's error body is `{"error": {"status": "UNREGISTERED", ...}}`. Anything else — an HTML
     * error page from a proxy, an empty body from a dropped connection — reads as unknown, which is
     * treated as transient.
     */
    private function readErrorStatus(string $body): string
    {
        try {
            $decoded = $this->serializer->unserialize($body);
        } catch (\InvalidArgumentException $exception) {
            return '';
        }

        return is_array($decoded) && isset($decoded['error']['status'])
            ? (string)$decoded['error']['status']
            : '';
    }

    /**
     * The configured project id, or the one inside the service-account key when the field is blank —
     * they are always the same value, and asking for it twice is a way to get them out of step.
     *
     * @throws LocalizedException
     */
    private function resolveProjectId(int $websiteId): string
    {
        $configured = $this->config->getPushProjectId($websiteId);

        if ($configured !== '') {
            return $configured;
        }

        $fromKey = $this->readServiceAccount($websiteId)->projectId;

        if ($fromKey === '') {
            throw new LocalizedException(__('No Firebase project id is configured.'));
        }

        return $fromKey;
    }

    /**
     * @throws LocalizedException
     */
    private function readServiceAccount(int $websiteId): ServiceAccount
    {
        return ServiceAccount::fromJson($this->config->getPushServiceAccountJson($websiteId), $this->serializer);
    }
}
