<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\FraudGuard\Model\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    public function testReadsTheKillSwitchInStoreScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('scr1be_fraud_guard/general/enabled', ScopeInterface::SCOPE_STORE, 7)
            ->willReturn(true);

        $this->assertTrue($this->config->isEnabled(7));
    }

    public function testReturnsTheConfiguredDeclineMessage(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('  Card declined.  ');

        $this->assertSame('Card declined.', $this->config->getDeclineMessage(1));
    }

    /**
     * An empty error bubble is a louder tell than any wording, so a blanked field must not
     * produce one.
     *
     * @dataProvider blankMessageProvider
     */
    public function testFallsBackWhenTheAdminBlanksTheMessage(mixed $stored): void
    {
        $this->scopeConfig->method('getValue')->willReturn($stored);

        $this->assertSame(Config::FALLBACK_DECLINE_MESSAGE, $this->config->getDeclineMessage(1));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function blankMessageProvider(): array
    {
        return [
            'never saved' => [null],
            'saved empty' => [''],
            'whitespace only' => ["  \n "],
        ];
    }

    public function testReadsTheAttemptLoggingFlagInStoreScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('scr1be_fraud_guard/general/log_attempts', ScopeInterface::SCOPE_STORE, null)
            ->willReturn(false);

        $this->assertFalse($this->config->isAttemptLoggingEnabled());
    }
}
