<?php
declare(strict_types=1);

namespace Scr1be\AdminGridToolkit\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Scr1be\AdminGridToolkit\Model\Config;

class ConfigTest extends TestCase
{
    private const PATH_ENABLED = 'scr1be_admin_grid_toolkit/general/enabled';
    private const PATH_DECODE_EXPORTS = 'scr1be_admin_grid_toolkit/general/decode_exports';
    private const PATH_DEJOIN_GRID_COUNT = 'scr1be_admin_grid_toolkit/general/dejoin_grid_count';
    private const PATH_REORDER_INCREMENT_ID = 'scr1be_admin_grid_toolkit/general/reorder_increment_id';

    /**
     * @var array<string, bool>
     */
    private array $flags = [];

    private Config $config;

    protected function setUp(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            fn (string $path): bool => $this->flags[$path] ?? false
        );

        $this->config = new Config($scopeConfig);
    }

    public function testEachFixReadsItsOwnFlag(): void
    {
        $this->flags = [
            self::PATH_ENABLED => true,
            self::PATH_DECODE_EXPORTS => true,
            self::PATH_DEJOIN_GRID_COUNT => false,
            self::PATH_REORDER_INCREMENT_ID => true,
        ];

        $this->assertTrue($this->config->isExportDecodingEnabled());
        $this->assertFalse($this->config->isGridCountDejoinEnabled());
        $this->assertTrue($this->config->isReorderIncrementIdFixEnabled());
    }

    /**
     * The master switch is folded into every reader precisely so that no call site has to remember
     * it. Three fixes on and the master off has to read as three fixes off.
     */
    public function testTheMasterSwitchOverridesEveryFix(): void
    {
        $this->flags = [
            self::PATH_ENABLED => false,
            self::PATH_DECODE_EXPORTS => true,
            self::PATH_DEJOIN_GRID_COUNT => true,
            self::PATH_REORDER_INCREMENT_ID => true,
        ];

        $this->assertFalse($this->config->isEnabled());
        $this->assertFalse($this->config->isExportDecodingEnabled());
        $this->assertFalse($this->config->isGridCountDejoinEnabled());
        $this->assertFalse($this->config->isReorderIncrementIdFixEnabled());
    }

    public function testAnUnsetInstallationReadsAsOff(): void
    {
        $this->flags = [];

        $this->assertFalse($this->config->isEnabled());
        $this->assertFalse($this->config->isExportDecodingEnabled());
        $this->assertFalse($this->config->isGridCountDejoinEnabled());
        $this->assertFalse($this->config->isReorderIncrementIdFixEnabled());
    }
}
