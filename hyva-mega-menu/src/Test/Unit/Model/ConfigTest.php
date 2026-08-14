<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMegaMenu\Model\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    public function testReadsTheRootCategoryAtStoreScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('scr1be_mega_menu/menu/default_root', ScopeInterface::SCOPE_STORE, 3)
            ->willReturn('5');

        $this->assertSame(5, $this->config->getDefaultRootCategoryId(3));
    }

    /**
     * Category id 0 is Magento's "this store has no root category" sentinel, never a category a
     * merchant could have meant. It has to read the same as an empty field, or the resolution
     * chain stops at a candidate that can never be active.
     *
     * @dataProvider absentRootProvider
     */
    public function testAnUnusableRootReadsAsAbsent(?string $stored): void
    {
        $this->scopeConfig->method('getValue')->willReturn($stored);

        $this->assertNull($this->config->getDefaultRootCategoryId(1));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function absentRootProvider(): array
    {
        return [
            'never set' => [null],
            'cleared' => [''],
            'zero' => ['0'],
            'not a number' => ['women'],
        ];
    }

    public function testReadsTheGroupMapAsRawText(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('scr1be_mega_menu/menu/group_map', ScopeInterface::SCOPE_STORE, null)
            ->willReturn("2:8\n");

        $this->assertSame("2:8\n", $this->config->getGroupMapRaw());
    }

    public function testAnUnsetGroupMapIsAnEmptyString(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $this->assertSame('', $this->config->getGroupMapRaw(1));
    }

    public function testThirdLevelIsAFlagAtStoreScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('scr1be_mega_menu/menu/third_level', ScopeInterface::SCOPE_STORE, 7)
            ->willReturn(false);

        $this->assertFalse($this->config->isThirdLevelEnabled(7));
    }
}
