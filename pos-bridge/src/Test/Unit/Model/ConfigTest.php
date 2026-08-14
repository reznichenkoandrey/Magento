<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\PosBridge\Model\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    public function testTheBridgeIsOffWhenTheMasterSwitchIsOff(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('scr1be_pos_bridge/general/enabled')
            ->willReturn(false);

        $this->assertFalse($this->config->isEnabled());
    }

    /**
     * The whole point of the second switch is that it can be off while the first is on.
     */
    public function testImpersonationCanBeOffWhileTheLookupIsOn(): void
    {
        $this->stubFlags([
            'scr1be_pos_bridge/general/enabled' => true,
            'scr1be_pos_bridge/general/impersonation_enabled' => false,
        ]);

        $this->assertTrue($this->config->isEnabled());
        $this->assertFalse($this->config->isImpersonationEnabled());
    }

    /**
     * …and the master switch has to dominate it, or turning the bridge off would leave the more
     * dangerous of the two endpoints running.
     */
    public function testTheMasterSwitchAlsoClosesImpersonation(): void
    {
        $this->stubFlags([
            'scr1be_pos_bridge/general/enabled' => false,
            'scr1be_pos_bridge/general/impersonation_enabled' => true,
        ]);

        $this->assertFalse($this->config->isImpersonationEnabled());
    }

    public function testBothSwitchesOnMeansImpersonationIsOn(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);

        $this->assertTrue($this->config->isImpersonationEnabled());
    }

    /**
     * @dataProvider limitProvider
     */
    public function testTheResultLimitIsClamped(?string $stored, int $expected): void
    {
        $this->scopeConfig->method('getValue')
            ->with('scr1be_pos_bridge/search/result_limit')
            ->willReturn($stored);

        $this->assertSame($expected, $this->config->getResultLimit());
    }

    /**
     * @return array<string, array{0: string|null, 1: int}>
     */
    public static function limitProvider(): array
    {
        return [
            'a sane value passes through' => ['25', 25],
            'unset falls back to the default' => [null, Config::DEFAULT_RESULT_LIMIT],
            'empty falls back to the default' => ['', Config::DEFAULT_RESULT_LIMIT],
            'garbage falls back to the default' => ['not a number', Config::DEFAULT_RESULT_LIMIT],
            'zero falls back to the default' => ['0', Config::DEFAULT_RESULT_LIMIT],
            'negative falls back to the default' => ['-5', Config::DEFAULT_RESULT_LIMIT],
            'an absurd value is clamped down' => ['100000', Config::MAX_RESULT_LIMIT],
            'the maximum itself is kept' => ['100', Config::MAX_RESULT_LIMIT],
            'the minimum itself is kept' => ['1', Config::MIN_RESULT_LIMIT],
        ];
    }

    /**
     * Keyed on the config path alone. The scope arguments are defaults the production code never
     * passes, and pinning them in a value map would make the test fail for the wrong reason the day
     * core changes one.
     *
     * @param array<string, bool> $flags
     */
    private function stubFlags(array $flags): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->willReturnCallback(static fn (string $path): bool => $flags[$path] ?? false);
    }
}
