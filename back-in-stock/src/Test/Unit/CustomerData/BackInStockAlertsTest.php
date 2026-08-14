<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\CustomerData;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\BackInStock\CustomerData\BackInStockAlerts;
use Scr1be\BackInStock\Model\AlertItem;
use Scr1be\BackInStock\Model\AlertItemFormatter;
use Scr1be\BackInStock\Model\AlertItemProvider;
use Scr1be\BackInStock\Model\AlertScope;
use Scr1be\BackInStock\Model\AlertState;
use Scr1be\BackInStock\Model\Config;
use Scr1be\BackInStock\Model\QtyRules;
use Scr1be\BackInStock\Model\StorefrontScope;

/**
 * The section source has one job beyond delegating: never to fail.
 *
 * Every section on the page is assembled by one controller call, and an exception here turns the
 * whole `customer/section/load` response into a 400 — which empties the minicart, the wishlist
 * counter and the welcome message on every page of the site.
 */
class BackInStockAlertsTest extends TestCase
{
    private AlertItemProvider&MockObject $provider;
    private AlertItemFormatter&MockObject $formatter;
    private StorefrontScope&MockObject $storefrontScope;
    private Config&MockObject $config;
    private LoggerInterface&MockObject $logger;
    private BackInStockAlerts $section;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(AlertItemProvider::class);
        $this->formatter = $this->createMock(AlertItemFormatter::class);
        $this->storefrontScope = $this->createMock(StorefrontScope::class);
        $this->config = $this->createMock(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->section = new BackInStockAlerts(
            $this->provider,
            $this->formatter,
            $this->storefrontScope,
            $this->config,
            $this->logger
        );
    }

    public function testAGuestGetsAnEmptySectionWithoutAQuery(): void
    {
        $this->storefrontScope->method('current')->willReturn(new AlertScope(0, 0, 1, 1));
        $this->provider->expects($this->never())->method('getQueued');

        $this->assertSame(['count' => 0, 'items' => []], $this->section->getSectionData());
    }

    public function testASwitchedOffPopupCostsNothingPerRequest(): void
    {
        $this->storefrontScope->method('current')->willReturn(new AlertScope(7, 1, 1, 1));
        $this->config->method('isPopupEnabled')->willReturn(false);
        $this->provider->expects($this->never())->method('getQueued');

        $this->assertSame(['count' => 0, 'items' => []], $this->section->getSectionData());
    }

    public function testAnUnresolvableStoreIsAnEmptySectionRatherThanAThrow(): void
    {
        $this->storefrontScope->method('current')->willThrowException(new NoSuchEntityException());

        $this->assertSame(['count' => 0, 'items' => []], $this->section->getSectionData());
    }

    public function testAFailureInsideTheProviderIsLoggedAndSwallowed(): void
    {
        $this->storefrontScope->method('current')->willReturn(new AlertScope(7, 1, 1, 1));
        $this->config->method('isPopupEnabled')->willReturn(true);
        $this->provider->method('getQueued')->willThrowException(new \RuntimeException('index is rebuilding'));

        $this->logger->expects($this->once())->method('error');

        $this->assertSame(['count' => 0, 'items' => []], $this->section->getSectionData());
    }

    public function testTheCountIsDerivedRatherThanTrusted(): void
    {
        $items = [$this->item(), $this->item()];

        $this->storefrontScope->method('current')->willReturn(new AlertScope(7, 1, 1, 1));
        $this->config->method('isPopupEnabled')->willReturn(true);
        $this->provider->method('getQueued')->willReturn($items);
        $this->formatter->method('toArray')->willReturn(['alert_id' => 1]);

        $data = $this->section->getSectionData();

        $this->assertSame(2, $data['count']);
        $this->assertCount(2, $data['items']);
    }

    public function testTheItemListIsReindexedSoJsonEncodesItAsAnArray(): void
    {
        // `array_map` over a keyed list preserves the keys, and `json_encode` turns a list with
        // non-sequential keys into an object — at which point `x-for` in the template iterates
        // nothing.
        $this->storefrontScope->method('current')->willReturn(new AlertScope(7, 1, 1, 1));
        $this->config->method('isPopupEnabled')->willReturn(true);
        $this->provider->method('getQueued')->willReturn([$this->item()]);
        $this->formatter->method('toArray')->willReturn(['alert_id' => 1]);

        $this->assertSame([0], array_keys($this->section->getSectionData()['items']));
    }

    private function item(): AlertItem
    {
        return new AlertItem(
            1,
            $this->createMock(Product::class),
            AlertState::ALERT_SENT,
            AlertState::POPUP_QUEUED,
            null,
            null,
            10.0,
            10.0,
            0,
            0,
            true,
            false,
            QtyRules::unrestricted(),
            []
        );
    }
}
