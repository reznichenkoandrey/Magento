<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Test\Unit\ViewModel;

use Magento\Framework\UrlInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CustomerGroupGuard\Model\Config;
use Scr1be\CustomerGroupGuard\ViewModel\ForceLogout;

class ForceLogoutTest extends TestCase
{
    private Config&MockObject $config;
    private UrlInterface&MockObject $url;
    private ForceLogout $viewModel;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->url = $this->createMock(UrlInterface::class);
        $this->viewModel = new ForceLogout($this->config, $this->url);
    }

    /**
     * Store-scoped, and read with no store id so it resolves the store the page is rendering
     * for — the same store the full-page cache keys that page under.
     */
    public function testReportsTheSoftPathSetting(): void
    {
        $this->config->expects($this->once())
            ->method('isForceLogoutEnabled')
            ->willReturn(false);

        $this->assertFalse($this->viewModel->isEnabled());
    }

    public function testBuildsTheCoreLogoutUrl(): void
    {
        $this->url->expects($this->once())
            ->method('getUrl')
            ->with('customer/account/logout')
            ->willReturn('https://shop.test/customer/account/logout/');

        $this->assertSame(
            'https://shop.test/customer/account/logout/',
            $this->viewModel->getLogoutUrl()
        );
    }
}
