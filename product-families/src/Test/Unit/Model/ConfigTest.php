<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Model\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    public function testReadsTheFamilySwitchFromItsOwnGroup(): void
    {
        $this->scopeConfig->method('isSetFlag')
            ->with('scr1be_product_families/other_sizes/enabled')
            ->willReturn(true);

        $this->assertTrue($this->config->isFamilyEnabled('other_sizes'));
    }

    public function testAttributeCodesAreTrimmed(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('  color  ');

        $this->assertSame('color', $this->config->getVariantAttribute('other_colors'));
    }

    public function testAnUnsetAttributeReadsAsTheEmptyString(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $this->assertSame('', $this->config->getGroupAttribute('similar'));
    }

    /**
     * @dataProvider outOfRangeMemberCounts
     */
    public function testAnOutOfRangeChipCountFallsBackToTheDefault(mixed $configured): void
    {
        $this->scopeConfig->method('getValue')->willReturn($configured);

        $this->assertSame(Config::DEFAULT_MAX_MEMBERS, $this->config->getMaxMembers('other_colors'));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function outOfRangeMemberCounts(): array
    {
        return [
            'zero means the merchant wanted the family off' => [0],
            'negative' => [-4],
            'above the write-volume ceiling' => [51],
            'not a number at all' => ['many'],
            'never saved' => [null],
        ];
    }

    /**
     * @dataProvider inRangeMemberCounts
     */
    public function testAnInRangeChipCountIsHonoured(mixed $configured, int $expected): void
    {
        $this->scopeConfig->method('getValue')->willReturn($configured);

        $this->assertSame($expected, $this->config->getMaxMembers('other_colors'));
    }

    /**
     * @return array<string, array{0: mixed, 1: int}>
     */
    public static function inRangeMemberCounts(): array
    {
        return [
            'lower bound' => [1, 1],
            'upper bound' => [50, 50],
            'string from the config table' => ['24', 24],
        ];
    }

    /**
     * The one store-scoped read in the module: the row heading is a string on a page, everything
     * else feeds a table that has no store column.
     */
    public function testTheRowHeadingIsReadInStoreScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('getValue')
            ->with('scr1be_product_families/similar/label', ScopeInterface::SCOPE_STORE, 3)
            ->willReturn('Similar products');

        $this->assertSame('Similar products', $this->config->getLabel('similar', 3));
    }
}
