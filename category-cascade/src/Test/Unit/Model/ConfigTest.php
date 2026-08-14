<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CategoryCascade\Model\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    public function testReadsTheKillSwitchInTheScopeOfTheSavedCategory(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('scr1be_category_cascade/general/enabled', ScopeInterface::SCOPE_STORE, 4)
            ->willReturn(true);

        $this->assertTrue($this->config->isCascadeEnabled(4));
    }

    public function testReadsTheConfirmPromptFlagInStoreScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('scr1be_category_cascade/general/confirm_prompt', ScopeInterface::SCOPE_STORE, null)
            ->willReturn(false);

        $this->assertFalse($this->config->isConfirmPromptEnabled());
    }

    public function testReadsTheLoggingFlagInStoreScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('scr1be_category_cascade/general/log_cascades', ScopeInterface::SCOPE_STORE, 0)
            ->willReturn(true);

        $this->assertTrue($this->config->isCascadeLoggingEnabled(0));
    }

    /**
     * The product-count setting has no store scope on purpose: it changes how an admin grid builds
     * a number, and the admin has no store view of its own to inherit from.
     */
    public function testReadsTheProductCountFlagInDefaultScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('scr1be_category_cascade/product_count/use_index')
            ->willReturn(true);

        $this->assertTrue($this->config->isIndexedProductCountEnabled());
    }
}
