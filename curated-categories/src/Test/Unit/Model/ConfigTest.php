<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Model\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    /**
     * The source code is the config group, which is what lets one Config serve every adapter and a
     * new adapter need no code here at all.
     */
    public function testBuildsSourcePathsFromTheSourceCode(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('scr1be_curated_categories/new_arrivals/enabled')
            ->willReturn(true);

        $this->assertTrue($this->config->isSourceEnabled('new_arrivals'));
    }

    public function testAnUnsetCategoryIsZeroRatherThanCategoryZero(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $this->assertSame(0, $this->config->getCategoryId('bestsellers'));
    }

    /**
     * @dataProvider limitProvider
     */
    public function testLimitFallsBackBelowTheMinimumAndClampsAboveTheMaximum(
        mixed $stored,
        int $expected
    ): void {
        $this->scopeConfig->method('getValue')->willReturn($stored);

        $this->assertSame($expected, $this->config->getLimit('bestsellers'));
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function limitProvider(): array
    {
        return [
            'sane value passes through' => [36, 36],
            'zero falls back' => [0, Config::DEFAULT_LIMIT],
            'negative falls back' => [-5, Config::DEFAULT_LIMIT],
            'unset falls back' => [null, Config::DEFAULT_LIMIT],
            'absurd value clamps' => [999999, Config::MAX_LIMIT],
        ];
    }

    /**
     * @dataProvider windowProvider
     */
    public function testWindowFallsBackBelowTheMinimumAndClampsAboveTheMaximum(
        mixed $stored,
        int $expected
    ): void {
        $this->scopeConfig->method('getValue')->willReturn($stored);

        $this->assertSame($expected, $this->config->getWindowDays('bestsellers'));
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function windowProvider(): array
    {
        return [
            'sane value passes through' => [7, 7],
            'zero falls back' => [0, Config::DEFAULT_WINDOW_DAYS],
            'unset falls back' => ['', Config::DEFAULT_WINDOW_DAYS],
            'a decade clamps to a year' => [3650, Config::MAX_WINDOW_DAYS],
        ];
    }

    /**
     * The floor is the guarantee that a curated category is never an empty page, so zero is not a
     * value a merchant can reach through the form. Switching the guard off means switching the
     * source off.
     */
    public function testTheFloorCanNeverBeLowerThanOne(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(0);

        $this->assertSame(Config::MIN_FLOOR, $this->config->getMinimumFloor('coming_soon'));
    }

    public function testTheFloorPassesAConfiguredValueThrough(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('12');

        $this->assertSame(12, $this->config->getMinimumFloor('coming_soon'));
    }

    /**
     * `ArraySerialized` hands back `false` for a field that has never been saved and a string when
     * the row is unparseable. Neither is a rule set, and neither may reach the reader.
     */
    public function testExclusionRulesAreOnlyEverAnArray(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(false);

        $this->assertSame([], $this->config->getExclusionRules('new_arrivals'));
    }

    public function testTheStorefrontMessageIsReadInStoreScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('scr1be_curated_categories/coming_soon/message', ScopeInterface::SCOPE_STORE, 3)
            ->willReturn('  Back on {date}.  ');

        $this->assertSame('Back on {date}.', $this->config->getArrivalMessage('coming_soon', 3));
    }
}
