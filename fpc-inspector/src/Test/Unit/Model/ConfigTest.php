<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\FpcInspector\Model\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    public function testEveryReadStaysInTheDefaultScope(): void
    {
        // The hooks fire while the store for the request is still being resolved, so asking for a
        // store-scoped value would answer for the wrong store or force resolution early.
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('scr1be_fpc_inspector/general/enabled')
            ->willReturn(true);

        $this->assertTrue($this->config->isEnabled());
    }

    public function testAnUnsetOrNonsenseStackDepthFallsBackToTheDefault(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0');

        $this->assertSame(Config::DEFAULT_STACK_DEPTH, $this->config->getStackDepth());
    }

    public function testAnOversizedStackDepthIsClamped(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('9000');

        $this->assertSame(Config::MAX_STACK_DEPTH, $this->config->getStackDepth());
    }

    public function testAReasonableStackDepthIsHonoured(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('25');

        $this->assertSame(25, $this->config->getStackDepth());
    }

    public function testAnEmptyUriFilterRecordsEverything(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('');

        $this->assertSame([], $this->config->getUriNeedles());
        $this->assertTrue($this->config->matchesUri('/anything/at/all'));
    }

    public function testNeedlesAreTrimmedAndBlanksDropped(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(' /gear/bags.html , , /checkout ,');

        $this->assertSame(['/gear/bags.html', '/checkout'], $this->config->getUriNeedles());
    }

    public function testAUriMatchesWhenAnyNeedleIsASubstring(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('/gear/bags.html,/checkout');

        $this->assertTrue($this->config->matchesUri('/gear/bags.html?product_list_order=price'));
        $this->assertTrue($this->config->matchesUri('/checkout/cart/'));
        $this->assertFalse($this->config->matchesUri('/women/tops-women.html'));
    }

    public function testRegexMetacharactersInTheFilterAreTakenLiterally(): void
    {
        // A pasted URL full of ? and . must narrow the log, never widen it.
        $this->scopeConfig->method('getValue')->willReturn('/gear/bags.html?p=2');

        $this->assertTrue($this->config->matchesUri('/gear/bags.html?p=2'));
        $this->assertFalse($this->config->matchesUri('/gear/bagsXhtmlZpM2'));
    }
}
