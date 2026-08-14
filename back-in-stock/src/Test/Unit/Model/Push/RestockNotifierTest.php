<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Model\Push;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\BackInStock\Api\PushTransportInterface;
use Scr1be\BackInStock\Model\Config;
use Scr1be\BackInStock\Model\DeviceTokenRegistry;
use Scr1be\BackInStock\Model\Push\PushMessage;
use Scr1be\BackInStock\Model\Push\PushResult;
use Scr1be\BackInStock\Model\Push\RestockNotifier;

class RestockNotifierTest extends TestCase
{
    private Config&MockObject $config;
    private DeviceTokenRegistry&MockObject $registry;
    private PushTransportInterface&MockObject $transport;
    private ProductRepositoryInterface&MockObject $productRepository;
    private LoggerInterface&MockObject $logger;
    private RestockNotifier $notifier;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->registry = $this->createMock(DeviceTokenRegistry::class);
        $this->transport = $this->createMock(PushTransportInterface::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->notifier = new RestockNotifier(
            $this->config,
            $this->registry,
            $this->transport,
            $this->productRepository,
            $this->logger
        );
    }

    public function testAnInstallationWithPushOffCostsOneConfigRead(): void
    {
        $this->config->method('isPushEnabled')->willReturn(false);
        $this->registry->expects($this->never())->method('getActiveTokens');
        $this->transport->expects($this->never())->method('send');

        $this->notifier->notify(7, 42, 1, 3);
    }

    public function testACustomerWithNoDevicesIsNeverLookedUp(): void
    {
        // The product load is the expensive part, and there is no point paying for it to build a
        // message that has nobody to go to.
        $this->config->method('isPushEnabled')->willReturn(true);
        $this->registry->method('getActiveTokens')->willReturn([]);
        $this->productRepository->expects($this->never())->method('getById');

        $this->notifier->notify(7, 42, 1, 3);
    }

    public function testTheProductIsLoadedInTheStoreTheAlertBelongsTo(): void
    {
        // Not the store the cron process happens to be in: the name and the url in the notification
        // have to be the ones the customer would see.
        $this->config->method('isPushEnabled')->willReturn(true);
        $this->config->method('getPushTitle')->willReturn('Back in stock');
        $this->registry->method('getActiveTokens')->willReturn(['token-a']);
        $this->productRepository->expects($this->once())
            ->method('getById')
            ->with(42, false, 3)
            ->willReturn($this->product());
        $this->transport->method('send')->willReturn(new PushResult(1));

        $this->notifier->notify(7, 42, 1, 3);
    }

    public function testTheMessageCarriesTheProductNameAsItsBody(): void
    {
        $this->config->method('isPushEnabled')->willReturn(true);
        $this->config->method('getPushTitle')->willReturn('We saved you one');
        $this->registry->method('getActiveTokens')->willReturn(['token-a']);
        $this->productRepository->method('getById')->willReturn($this->product());

        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(static function (PushMessage $message): bool {
                    return $message->title === 'We saved you one'
                        && $message->body === 'Chaz Kangeroo Hoodie'
                        && $message->url === 'https://example.test/chaz.html'
                        && $message->data === ['product_id' => '42', 'sku' => 'MH01'];
                }),
                ['token-a']
            )
            ->willReturn(new PushResult(1));

        $this->notifier->notify(7, 42, 1, 3);
    }

    public function testTokensTheTransportRefusedAreRetired(): void
    {
        $this->config->method('isPushEnabled')->willReturn(true);
        $this->registry->method('getActiveTokens')->willReturn(['live', 'dead']);
        $this->productRepository->method('getById')->willReturn($this->product());
        $this->transport->method('send')->willReturn(new PushResult(1, ['dead'], ['HTTP 404 UNREGISTERED']));

        $this->registry->expects($this->once())->method('retire')->with(['dead']);

        $this->notifier->notify(7, 42, 1, 3);
    }

    public function testATransientFailureRetiresNothing(): void
    {
        // A 503 is Google having a bad minute. Deactivating on it would quietly unsubscribe real
        // customers a few outages in.
        $this->config->method('isPushEnabled')->willReturn(true);
        $this->registry->method('getActiveTokens')->willReturn(['live']);
        $this->productRepository->method('getById')->willReturn($this->product());
        $this->transport->method('send')->willReturn(new PushResult(0, [], ['HTTP 503 unknown']));

        $this->registry->expects($this->never())->method('retire');
        $this->logger->expects($this->once())->method('warning');

        $this->notifier->notify(7, 42, 1, 3);
    }

    public function testAMissingProductIsLoggedRatherThanThrown(): void
    {
        // The caller is an observer inside the alert mail run, which is holding a half-built email.
        // A push notification is never worth taking that down.
        $this->config->method('isPushEnabled')->willReturn(true);
        $this->registry->method('getActiveTokens')->willReturn(['token-a']);
        $this->productRepository->method('getById')->willThrowException(new NoSuchEntityException());

        $this->logger->expects($this->once())->method('error');

        $this->notifier->notify(7, 42, 1, 3);
    }

    public function testAGuestIsNeverNotified(): void
    {
        $this->config->expects($this->never())->method('isPushEnabled');

        $this->notifier->notify(0, 42, 1, 3);
    }

    private function product(): ProductInterface
    {
        $product = $this->createMock(Product::class);
        $product->method('getName')->willReturn('Chaz Kangeroo Hoodie');
        $product->method('getSku')->willReturn('MH01');
        $product->method('getProductUrl')->willReturn('https://example.test/chaz.html');

        return $product;
    }
}
