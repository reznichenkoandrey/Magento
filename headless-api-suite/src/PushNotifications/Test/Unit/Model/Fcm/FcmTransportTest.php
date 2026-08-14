<?php
declare(strict_types=1);

namespace Scr1be\PushNotifications\Test\Unit\Model\Fcm;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\PushNotifications\Model\Config;
use Scr1be\PushNotifications\Model\Fcm\AccessTokenProvider;
use Scr1be\PushNotifications\Model\Fcm\FcmTransport;
use Scr1be\PushNotifications\Model\PushMessage;

/**
 * What the transport makes of FCM's answers, which is the part that decides whether the registry
 * grows dead rows forever.
 */
class FcmTransportTest extends TestCase
{
    private Curl&MockObject $curl;
    private LoggerInterface&MockObject $logger;
    private Config&MockObject $config;
    private FcmTransport $transport;

    /**
     * @var array{0: string, 1: string}|null
     */
    private ?array $posted = null;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('post')->willReturnCallback(
            function ($uri, $body) {
                $this->posted = [(string)$uri, (string)$body];
            }
        );

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $this->config = $this->createMock(Config::class);
        $this->config->method('getServiceAccountKey')->willReturn($this->key());

        $accessTokenProvider = $this->createMock(AccessTokenProvider::class);
        $accessTokenProvider->method('getToken')->willReturn('ya29.access-token');

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->transport = new FcmTransport(
            $this->config,
            $accessTokenProvider,
            $curlFactory,
            new Json(),
            $this->logger
        );
    }

    public function testPostsToTheProjectsSendEndpoint(): void
    {
        $this->respond(200, '{"name":"projects/demo-project/messages/1"}');

        $this->transport->send(new PushMessage('token-1', 'Title', 'Body', ['order_id' => '42']));

        $this->assertSame('https://fcm.googleapis.com/v1/projects/demo-project/messages:send', $this->posted[0]);
        $this->assertSame(
            [
                'message' => [
                    'token' => 'token-1',
                    'notification' => ['title' => 'Title', 'body' => 'Body'],
                    'data' => ['order_id' => '42'],
                ],
            ],
            json_decode($this->posted[1], true)
        );
    }

    public function testA200IsDelivered(): void
    {
        $this->respond(200, '{"name":"projects/demo/messages/1"}');

        $this->assertTrue($this->transport->send($this->message())->delivered);
    }

    /**
     * The self-healing signal. Without recognising it, every uninstalled app costs a request on every
     * order for the rest of the installation's life.
     */
    public function testUnregisteredMarksTheTokenDead(): void
    {
        $this->respond(404, $this->error('NOT_FOUND', 'UNREGISTERED'));

        $result = $this->transport->send($this->message());

        $this->assertFalse($result->delivered);
        $this->assertTrue($result->tokenIsDead);
        $this->assertSame('UNREGISTERED', $result->reason);
    }

    public function testInvalidArgumentAlsoMarksTheTokenDead(): void
    {
        $this->respond(400, $this->error('INVALID_ARGUMENT', 'INVALID_ARGUMENT'));

        $this->assertTrue($this->transport->send($this->message())->tokenIsDead);
    }

    /**
     * A 503 is FCM having a bad afternoon. Deactivating a perfectly good device over it would
     * unsubscribe real customers.
     */
    public function testATransientFailureLeavesTheTokenAlone(): void
    {
        $this->respond(503, '{"error":{"status":"UNAVAILABLE"}}');

        $result = $this->transport->send($this->message());

        $this->assertFalse($result->delivered);
        $this->assertFalse($result->tokenIsDead);
    }

    /**
     * `error.status` is NOT_FOUND both for a dead token and for a wrong project id; only the
     * `FcmError` detail distinguishes them, so a body without one must not read as a dead token.
     */
    public function testANotFoundWithoutAnFcmErrorDetailIsNotADeadToken(): void
    {
        $this->respond(404, '{"error":{"status":"NOT_FOUND","message":"Requested entity was not found."}}');

        $this->assertFalse($this->transport->send($this->message())->tokenIsDead);
    }

    public function testAnUnparsableErrorBodyIsATransientFailure(): void
    {
        $this->respond(500, '<html>502 Bad Gateway</html>');

        $result = $this->transport->send($this->message());

        $this->assertFalse($result->delivered);
        $this->assertFalse($result->tokenIsDead);
    }

    /**
     * With no key configured the transport must be inert and loud in the log, not throw into an order
     * save.
     */
    public function testAnUnconfiguredTransportFailsQuietly(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getServiceAccountKey')->willReturn('');
        $this->logger->expects($this->once())->method('error');

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->expects($this->never())->method('create');

        $transport = new FcmTransport(
            $config,
            $this->createMock(AccessTokenProvider::class),
            $curlFactory,
            new Json(),
            $this->logger
        );

        $result = $transport->send($this->message());

        $this->assertFalse($result->delivered);
        $this->assertFalse($result->tokenIsDead);
    }

    /**
     * FCM's `data` map is string-to-string and rejects anything else with INVALID_ARGUMENT — which
     * this transport reads as a dead token, so a stray integer would silently unsubscribe a device.
     */
    public function testDataValuesAreCoercedToStrings(): void
    {
        $this->respond(200, '{}');

        $this->transport->send(new PushMessage('token-1', 'T', 'B', ['order_id' => 42, 'flag' => true]));

        $payload = json_decode($this->posted[1], true);
        $this->assertSame(['order_id' => '42', 'flag' => '1'], $payload['message']['data']);
    }

    private function message(): PushMessage
    {
        return new PushMessage('token-1', 'Title', 'Body');
    }

    private function respond(int $status, string $body): void
    {
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
    }

    private function error(string $status, string $errorCode): string
    {
        return (string)json_encode([
            'error' => [
                'status' => $status,
                'message' => 'nope',
                'details' => [
                    ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => $errorCode],
                ],
            ],
        ]);
    }

    private function key(): string
    {
        return (string)json_encode([
            'project_id' => 'demo-project',
            'client_email' => 'push@demo-project.iam.gserviceaccount.com',
            'private_key' => '-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----\n',
        ]);
    }
}
