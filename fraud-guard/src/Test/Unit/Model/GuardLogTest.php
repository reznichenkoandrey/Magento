<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Test\Unit\Model;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Quote\Api\Data\CartInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\FraudGuard\Model\Config;
use Scr1be\FraudGuard\Model\GuardLog;

class GuardLogTest extends TestCase
{
    private const STORE_ID = 3;

    private LoggerInterface&MockObject $logger;
    private Config&MockObject $config;
    private RemoteAddress&MockObject $remoteAddress;
    private HttpRequest&MockObject $request;
    private GuardLog $log;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->remoteAddress = $this->createMock(RemoteAddress::class);
        $this->request = $this->createMock(HttpRequest::class);

        $this->log = new GuardLog($this->logger, $this->config, $this->remoteAddress, $this->request);
    }

    public function testRecordsTheForensicsThatMakeASeriesLegible(): void
    {
        $this->config->method('isAttemptLoggingEnabled')->with(self::STORE_ID)->willReturn(true);
        $this->remoteAddress->method('getRemoteAddress')->willReturn('198.51.100.7');
        $this->request->method('getHeader')->with('User-Agent')->willReturn('curl/8.4.0');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->isType('string'),
                $this->callback(static function (array $context): bool {
                    return $context['customer_id'] === 42
                        && $context['customer_email'] === 'shopper@example.com'
                        && $context['quote_id'] === 17
                        && $context['store_id'] === self::STORE_ID
                        && $context['ip'] === '198.51.100.7'
                        && $context['user_agent'] === 'curl/8.4.0';
                })
            );

        $this->log->blockedAttempt($this->quote(), 42);
    }

    public function testAttemptLoggingCanBeTurnedOff(): void
    {
        $this->config->method('isAttemptLoggingEnabled')->willReturn(false);
        $this->logger->expects($this->never())->method('warning');

        $this->log->blockedAttempt($this->quote(), 42);
    }

    public function testAMissingUserAgentIsRecordedAsNull(): void
    {
        $this->config->method('isAttemptLoggingEnabled')->willReturn(true);
        // Magento's request returns false, not null, for a header that is not present.
        $this->request->method('getHeader')->willReturn(false);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->isType('string'),
                $this->callback(static fn (array $context): bool => $context['user_agent'] === null)
            );

        $this->log->blockedAttempt($this->quote(), 42);
    }

    /**
     * A merchant who turned attempt logging off asked for less noise about carders, not for
     * silence about a guard that stopped working.
     */
    public function testLookupFailuresAreLoggedRegardlessOfTheAttemptSetting(): void
    {
        $this->config->method('isAttemptLoggingEnabled')->willReturn(false);
        $this->logger->expects($this->once())->method('error');

        $this->log->lookupFailed(42, new \RuntimeException('connection lost'));
    }

    private function quote(): CartInterface&MockObject
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('shopper@example.com');

        $quote = $this->createMock(CartInterface::class);
        $quote->method('getStoreId')->willReturn(self::STORE_ID);
        $quote->method('getId')->willReturn(17);
        $quote->method('getItemsCount')->willReturn(3);
        $quote->method('getCustomer')->willReturn($customer);

        return $quote;
    }
}
