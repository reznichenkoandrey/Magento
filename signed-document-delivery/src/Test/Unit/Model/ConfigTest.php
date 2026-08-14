<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\SignedDocumentDelivery\Model\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    public function testAConfiguredTtlInsideTheRangeIsUsedAsGiven(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('900');

        $this->assertSame(900, $this->config->getUrlTtl(1));
    }

    /**
     * @dataProvider unusableTtls
     */
    public function testATtlOutsideTheRangeFallsBackToTheDefault(?string $stored): void
    {
        $this->scopeConfig->method('getValue')->willReturn($stored);

        $this->assertSame(Config::DEFAULT_URL_TTL, $this->config->getUrlTtl(1));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function unusableTtls(): array
    {
        return [
            'zero, which is a module that does not work' => ['0'],
            'negative' => ['-60'],
            'below the floor' => [(string) (Config::MIN_URL_TTL - 1)],
            'above the ceiling' => [(string) (Config::MAX_URL_TTL + 1)],
            'a day, which throws the whole point away' => ['86400'],
            'not a number' => ['five minutes'],
            'empty field' => [''],
            'never configured' => [null],
        ];
    }

    /**
     * @dataProvider ttlBoundaries
     */
    public function testTheRangeIsInclusiveAtBothEnds(int $stored): void
    {
        $this->scopeConfig->method('getValue')->willReturn((string) $stored);

        $this->assertSame($stored, $this->config->getUrlTtl(1));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function ttlBoundaries(): array
    {
        return ['floor' => [Config::MIN_URL_TTL], 'ceiling' => [Config::MAX_URL_TTL]];
    }

    public function testTheTtlIsReadAtStoreScope(): void
    {
        // A native app and a kiosk in the same website can reasonably want different windows.
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('scr1be_signed_documents/delivery/url_ttl', ScopeInterface::SCOPE_STORE, 7)
            ->willReturn('300');

        $this->config->getUrlTtl(7);
    }

    public function testTheCacheLifetimeIsReadAtDefaultScopeOnly(): void
    {
        // The sweep is a cron job with no store context, so a per-store lifetime could not be
        // honoured — reading it at store scope would be a setting that silently does nothing.
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('scr1be_signed_documents/cache/lifetime')
            ->willReturn('3600');

        $this->assertSame(3600, $this->config->getCacheLifetime());
    }

    /**
     * @dataProvider unusableCacheLifetimes
     */
    public function testACacheLifetimeOutsideTheRangeFallsBackToTheDefault(?string $stored): void
    {
        $this->scopeConfig->method('getValue')->willReturn($stored);

        $this->assertSame(Config::DEFAULT_CACHE_LIFETIME, $this->config->getCacheLifetime());
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function unusableCacheLifetimes(): array
    {
        return [
            'zero would sweep everything every hour' => ['0'],
            'below the floor' => [(string) (Config::MIN_CACHE_LIFETIME - 1)],
            'a year' => [(string) (Config::MAX_CACHE_LIFETIME + 1)],
            'not a number' => ['forever'],
            'never configured' => [null],
        ];
    }
}
