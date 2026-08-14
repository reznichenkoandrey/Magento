<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductSlider\Model\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    public function testAWindowOfZeroDaysBecomesOne(): void
    {
        // A slider ranked over "the last 0 days" would silently show nothing, which is the failure
        // mode hardest to notice on a live page.
        $this->scopeConfig->method('getValue')->willReturn('0');

        $this->assertSame(1, $this->config->getBestsellersWindowDays(1));
        $this->assertSame(1, $this->config->getMostViewedWindowDays(1));
        $this->assertSame(1, $this->config->getPurchaseIndexWindowDays(1));
    }

    public function testANegativeWindowBecomesOne(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('-30');

        $this->assertSame(1, $this->config->getPurchaseIndexWindowDays(1));
    }

    public function testAnAbsurdWindowIsCapped(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('999999');

        $this->assertSame(3650, $this->config->getBestsellersWindowDays(1));
    }

    public function testTheSocialProofWindowIsBoundedInHours(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0');
        $this->assertSame(1, $this->config->getSocialProofWindowHours(1));
    }

    public function testTheSocialProofWindowIsCappedAtAYear(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('100000');

        $this->assertSame(8760, $this->config->getSocialProofWindowHours(1));
    }

    public function testANegativeTtlBecomesNoStoreRatherThanANegativeMaxAge(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('-1');

        // Zero is the endpoint's documented "no-store"; a negative max-age is not a header value.
        $this->assertSame(0, $this->config->getProofEndpointTtl(1));
    }

    public function testTheTtlIsCappedAtADay(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('999999');

        $this->assertSame(86400, $this->config->getProofEndpointTtl(1));
    }

    public function testANegativeCustomerGroupBecomesNotLoggedIn(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('-3');

        $this->assertSame(0, $this->config->getDealsCustomerGroupId(1));
    }

    public function testFlagsComeFromIsSetFlagSoThatYesNoStringsWork(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);

        $this->assertTrue($this->config->isEnabled(1));
        $this->assertTrue($this->config->isSocialProofEnabled(1));
        $this->assertTrue($this->config->isBuyerNameShown(1));
        $this->assertTrue($this->config->isBuyerCityShown(1));
    }
}
