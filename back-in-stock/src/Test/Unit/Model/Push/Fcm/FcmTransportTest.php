<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Model\Push\Fcm;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\BackInStock\Model\Config;
use Scr1be\BackInStock\Model\Push\Fcm\AccessTokenProvider;
use Scr1be\BackInStock\Model\Push\Fcm\FcmTransport;
use Scr1be\BackInStock\Model\Push\PushMessage;

/**
 * The classification is the whole test: which refusals mean "this device is gone" and which mean
 * "try again later". Getting it backwards either unsubscribes real customers on a bad afternoon or
 * keeps sending to browsers that were uninstalled a year ago.
 */
class FcmTransportTest extends TestCase
{
    private const SERVICE_ACCOUNT = '{"client_email":"a@b.c","private_key":"key","project_id":"my-project"}';

    private Curl&MockObject $curl;
    private Config&MockObject $config;
    private AccessTokenProvider&MockObject $accessTokenProvider;
    private LoggerInterface&MockObject $logger;
    private FcmTransport $transport;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $this->accessTokenProvider = $this->createMock(AccessTokenProvider::class);
        $this->accessTokenProvider->method('getToken')->willReturn('ya29.token');

        $this->config = $this->createMock(Config::class);
        $this->config->method('getPushServiceAccountJson')->willReturn(self::SERVICE_ACCOUNT);
        $this->config->method('getPushProjectId')->willReturn('my-project');

        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturn($website);

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->transport = new FcmTransport(
            $curlFactory,
            $this->accessTokenProvider,
            $this->config,
            $storeManager,
            new Json(),
            $this->logger
        );
    }

    public function testNoTokensMeansNoRequestAndNoTokenExchange(): void
    {
        $this->accessTokenProvider->expects($this->never())->method('getToken');

        $this->assertSame(0, $this->transport->send($this->message(), [])->delivered);
    }

    public function testASuccessfulSendIsCounted(): void
    {
        $this->curl->method('getStatus')->willReturn(200);

        $result = $this->transport->send($this->message(), ['token-a', 'token-b']);

        $this->assertSame(2, $result->delivered);
        $this->assertSame([], $result->invalidTokens);
    }

    /**
     * @dataProvider permanentRefusals
     */
    public function testAPermanentRefusalRetiresTheToken(int $status, string $error): void
    {
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn(sprintf('{"error":{"status":"%s"}}', $error));

        $result = $this->transport->send($this->message(), ['token-a']);

        $this->assertSame(0, $result->delivered);
        $this->assertSame(['token-a'], $result->invalidTokens);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function permanentRefusals(): array
    {
        return [
            'the app was uninstalled' => [404, 'UNREGISTERED'],
            'never was a token' => [400, 'INVALID_ARGUMENT'],
            'the registration is gone' => [404, 'NOT_FOUND'],
        ];
    }

    /**
     * @dataProvider transientRefusals
     */
    public function testATransientRefusalRetiresNothing(int $status, string $body): void
    {
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);

        $result = $this->transport->send($this->message(), ['token-a']);

        $this->assertSame(0, $result->delivered);
        $this->assertSame([], $result->invalidTokens);
        $this->assertCount(1, $result->errors);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function transientRefusals(): array
    {
        return [
            'rate limited' => [429, '{"error":{"status":"RESOURCE_EXHAUSTED"}}'],
            'service unavailable' => [503, '{"error":{"status":"UNAVAILABLE"}}'],
            'an html error page from a proxy' => [502, '<html><body>Bad Gateway</body></html>'],
            'an empty body' => [500, ''],
        ];
    }

    public function testANetworkFailureIsNotADeadDevice(): void
    {
        // `Magento\Framework\HTTP\Client\Curl::doError()` throws, and a DNS blip is not evidence
        // about anybody's browser.
        $this->curl->method('post')->willThrowException(new \Exception('Could not resolve host'));

        $result = $this->transport->send($this->message(), ['token-a']);

        $this->assertSame([], $result->invalidTokens);
        $this->assertStringContainsString('transport:', $result->errors[0]);
    }

    public function testOneDeadTokenDoesNotStopTheLiveOneBesideIt(): void
    {
        $this->curl->method('getStatus')->willReturnOnConsecutiveCalls(404, 200);
        $this->curl->method('getBody')->willReturn('{"error":{"status":"UNREGISTERED"}}');

        $result = $this->transport->send($this->message(), ['dead', 'live']);

        $this->assertSame(1, $result->delivered);
        $this->assertSame(['dead'], $result->invalidTokens);
    }

    public function testTheClickTargetTravelsUnderWebpushSoTheServiceWorkerOpensIt(): void
    {
        $this->curl->method('getStatus')->willReturn(200);

        $this->curl->expects($this->once())
            ->method('post')
            ->with(
                'https://fcm.googleapis.com/v1/projects/my-project/messages:send',
                $this->callback(function (string $body): bool {
                    $payload = (new Json())->unserialize($body)['message'];

                    return $payload['token'] === 'token-a'
                        && $payload['notification']['title'] === 'Back in stock'
                        && $payload['notification']['body'] === 'Chaz Kangeroo Hoodie'
                        && $payload['webpush']['fcm_options']['link'] === 'https://example.test/chaz.html'
                        && $payload['data'] === ['product_id' => '42'];
                })
            );

        $this->transport->send($this->message(), ['token-a']);
    }

    public function testTheBearerTokenIsSentAsAHeader(): void
    {
        $this->curl->method('getStatus')->willReturn(200);

        $headers = [];
        $this->curl->method('addHeader')->willReturnCallback(
            static function (string $name, string $value) use (&$headers): void {
                $headers[$name] = $value;
            }
        );

        $this->transport->send($this->message(), ['token-a']);

        $this->assertSame('Bearer ya29.token', $headers['Authorization']);
        $this->assertSame('application/json', $headers['Content-Type']);
    }

    public function testAMisconfigurationRetiresNothingAndSaysSoOnce(): void
    {
        // A missing service account is not evidence about anybody's device. Reporting the tokens as
        // invalid here would empty the registry the first time somebody rotated a key badly.
        $config = $this->createMock(Config::class);
        $config->method('getPushProjectId')->willReturn('my-project');
        $config->method('getPushServiceAccountJson')->willReturn('');

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturn($website);

        $this->logger->expects($this->once())->method('error');
        $this->curl->expects($this->never())->method('post');

        $transport = new FcmTransport(
            $curlFactory,
            $this->accessTokenProvider,
            $config,
            $storeManager,
            new Json(),
            $this->logger
        );

        $result = $transport->send($this->message(), ['token-a']);

        $this->assertSame(0, $result->delivered);
        $this->assertSame([], $result->invalidTokens);
    }

    private function message(): PushMessage
    {
        return new PushMessage(
            'Back in stock',
            'Chaz Kangeroo Hoodie',
            'https://example.test/chaz.html',
            ['product_id' => '42']
        );
    }
}
