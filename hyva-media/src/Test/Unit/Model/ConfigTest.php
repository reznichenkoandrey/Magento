<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMedia\Model\Config;

class ConfigTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, bool> */
    private array $flags = [];

    private ScopeConfigInterface&MockObject $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);

        // Registered once. A second ->method('getValue') on the same mock adds a second matcher
        // that also matches every call, and the first one registered is the one that answers.
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(fn (string $path): mixed => $this->values[$path] ?? null);
        $this->scopeConfig->method('isSetFlag')
            ->willReturnCallback(fn (string $path): bool => $this->flags[$path] ?? false);

        $this->config = new Config($this->scopeConfig);
    }

    public function testWidthsAreSortedAndDeduplicated(): void
    {
        // The ladder is filtered by a ceiling and then walked widest-first, and both steps assume
        // an ordered set. An admin typing rungs in the order they thought of them is normal.
        $this->values['scr1be_hyva_media/output/widths'] = '1024, 320,480,1024, 768';

        $this->assertSame([320, 480, 768, 1024], $this->config->getWidths());
    }

    public function testWidthsOutsideTheUsableRangeAreDropped(): void
    {
        $this->values['scr1be_hyva_media/output/widths'] = '4,320,99999,1920';

        $this->assertSame([320, 1920], $this->config->getWidths());
    }

    public function testAnUnusableWidthListFallsBackToTheDefaultLadder(): void
    {
        // An empty ladder would mean every image degenerates to its own single rung, which reads as
        // the module silently doing nothing rather than as a misconfiguration.
        $this->values['scr1be_hyva_media/output/widths'] = 'small, medium, large';

        $this->assertSame([320, 480, 768, 1024, 1440, 1920], $this->config->getWidths());
    }

    public function testQualityIsClampedIntoTheEncoderRange(): void
    {
        $this->values['scr1be_hyva_media/output/quality'] = '250';
        $this->assertSame(100, $this->config->getQuality());

        $this->values['scr1be_hyva_media/output/quality'] = '-5';
        $this->assertSame(1, $this->config->getQuality());
    }

    public function testAnEmptyQualityFieldMeansTheDefaultRatherThanZero(): void
    {
        // Clamping an empty string would produce quality 1 — a page of visibly destroyed images
        // from a field the admin merely cleared.
        $this->values['scr1be_hyva_media/output/quality'] = '';

        $this->assertSame(82, $this->config->getQuality());
    }

    public function testAnAbsentQualityFieldMeansTheDefault(): void
    {
        $this->assertSame(82, $this->config->getQuality());
    }

    public function testWebpQualityHasItsOwnDefault(): void
    {
        // Lower than the source-format default on purpose: matched visual quality sits a few points
        // lower on WebP's scale, and a WebP that is not smaller has no reason to be offered.
        $this->assertSame(78, $this->config->getWebpQuality());
    }

    public function testLimitsFallBackWhenClearedOrZeroed(): void
    {
        // Zero is not a meaningful ceiling for either field: it would switch the module off through
        // a limits field rather than through the enable flag that exists for the purpose.
        $this->values['scr1be_hyva_media/limits/max_source_megapixels'] = '0';
        $this->values['scr1be_hyva_media/limits/max_encodes_per_request'] = '';

        $this->assertSame(40, $this->config->getMaxSourceMegapixels());
        $this->assertSame(24, $this->config->getMaxEncodesPerRequest());
    }

    public function testLimitsAreReadWhenSet(): void
    {
        $this->values['scr1be_hyva_media/limits/max_source_megapixels'] = '12';
        $this->values['scr1be_hyva_media/limits/max_encodes_per_request'] = '6';

        $this->assertSame(12, $this->config->getMaxSourceMegapixels());
        $this->assertSame(6, $this->config->getMaxEncodesPerRequest());
    }

    public function testFlagsGoThroughIsSetFlagRatherThanGetValue(): void
    {
        // "0" out of getValue() is a truthy string; isSetFlag() is the only reading of a Yes/No
        // field that treats the No case as No.
        $this->flags['scr1be_hyva_media/output/enabled'] = true;
        $this->flags['scr1be_hyva_media/webp/enabled'] = false;

        $this->assertTrue($this->config->isEnabled());
        $this->assertFalse($this->config->isWebpEnabled());
    }
}
