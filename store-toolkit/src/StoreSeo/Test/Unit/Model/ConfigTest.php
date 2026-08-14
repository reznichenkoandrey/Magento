<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreSeo\Model\Config;

class ConfigTest extends TestCase
{
    /**
     * @var ScopeConfigInterface&MockObject
     */
    private $scopeConfig;

    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    public function testWhitelistIsTrimmedAndDeduplicated(): void
    {
        $this->stubWhitelist(' p , p ,product_list_limit, ');

        self::assertSame(['p', 'product_list_limit'], $this->config->getCanonicalQueryWhitelist(1));
    }

    public function testDeniedParametersCannotBeWhitelistedByHand(): void
    {
        $this->stubWhitelist('p,___store,___from_store,uenc,SID');

        self::assertSame(['p'], $this->config->getCanonicalQueryWhitelist(1));
    }

    public function testEmptyWhitelistYieldsNoParameters(): void
    {
        $this->stubWhitelist('');

        self::assertSame([], $this->config->getCanonicalQueryWhitelist(1));
    }

    public function testXDefaultStoreCodeIsNullWhenBlank(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_HREFLANG_X_DEFAULT_STORE)
            ->willReturn('   ');

        self::assertNull($this->config->getXDefaultStoreCode());
    }

    private function stubWhitelist(string $raw): void
    {
        $this->scopeConfig->method('getValue')
            ->with(Config::XML_PATH_CANONICAL_QUERY_WHITELIST, 'store', 1)
            ->willReturn($raw);
    }
}
