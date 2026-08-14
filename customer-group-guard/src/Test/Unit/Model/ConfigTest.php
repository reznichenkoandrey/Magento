<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CustomerGroupGuard\Model\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    public function testReadsTheMasterSwitchInStoreScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('scr1be_customer_group_guard/general/enabled', ScopeInterface::SCOPE_STORE, 7)
            ->willReturn(true);

        $this->assertTrue($this->config->isEnabled(7));
    }

    /**
     * The whole reason both readers live here: a master switch checked at four call sites is a
     * master switch that will be missed at one of them.
     *
     * @dataProvider gatedReaderProvider
     */
    public function testTheMasterSwitchGatesBothPaths(string $reader, string $path): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->willReturnMap([
                ['scr1be_customer_group_guard/general/enabled', ScopeInterface::SCOPE_STORE, 1, false],
                [$path, ScopeInterface::SCOPE_STORE, 1, true],
            ]);

        $this->assertFalse($this->config->{$reader}(1));
    }

    /**
     * @dataProvider gatedReaderProvider
     */
    public function testEachPathHasItsOwnSwitchOnTopOfTheMaster(string $reader, string $path): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->willReturnMap([
                ['scr1be_customer_group_guard/general/enabled', ScopeInterface::SCOPE_STORE, 1, true],
                [$path, ScopeInterface::SCOPE_STORE, 1, false],
            ]);

        $this->assertFalse($this->config->{$reader}(1));
    }

    /**
     * @dataProvider gatedReaderProvider
     */
    public function testAPathIsOnWhenBothItsSwitchAndTheMasterAre(string $reader, string $path): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->willReturnMap([
                ['scr1be_customer_group_guard/general/enabled', ScopeInterface::SCOPE_STORE, 1, true],
                [$path, ScopeInterface::SCOPE_STORE, 1, true],
            ]);

        $this->assertTrue($this->config->{$reader}(1));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gatedReaderProvider(): array
    {
        return [
            'soft path' => [
                'isForceLogoutEnabled',
                'scr1be_customer_group_guard/general/force_logout',
            ],
            'hard path' => [
                'isPlaceOrderBlockEnabled',
                'scr1be_customer_group_guard/general/block_place_order',
            ],
        ];
    }

    public function testAnUnscopedReadResolvesTheCurrentStore(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with($this->anything(), ScopeInterface::SCOPE_STORE, null)
            ->willReturn(true);

        $this->assertTrue($this->config->isEnabled());
    }
}
