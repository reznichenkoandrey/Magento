<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Model;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Model\Config;
use Scr1be\ProductFamilies\Model\FamilyDefinitionPool;
use Scr1be\ProductFamilies\Model\FamilyLinkType;

class FamilyDefinitionPoolTest extends TestCase
{
    private Config&MockObject $config;
    private FamilyDefinitionPool $pool;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->pool = new FamilyDefinitionPool($this->config, new FamilyLinkType());
    }

    public function testAnUnknownFamilyIsRefusedByName(): void
    {
        $this->assertSame('unknown family "nonsense"', $this->pool->getRefusalReason('nonsense'));
        $this->assertNull($this->pool->get('nonsense'));
    }

    public function testTheMasterSwitchIsCheckedBeforeTheFamilySwitch(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->config->expects($this->never())->method('isFamilyEnabled');

        $this->assertSame('the module is switched off', $this->pool->getRefusalReason('similar'));
    }

    public function testAFamilyWithoutAGroupAttributeIsRefused(): void
    {
        $this->configure(enabled: true, familyEnabled: true, groupAttribute: '');

        $this->assertSame(
            'family "other_colors" has no group attribute configured',
            $this->pool->getRefusalReason('other_colors')
        );
    }

    public function testAFullyConfiguredFamilyResolves(): void
    {
        $this->configure(
            enabled: true,
            familyEnabled: true,
            groupAttribute: 'style_general',
            variantAttribute: 'color',
            maxMembers: 6,
            distinctVariants: true,
            label: 'Other colours'
        );

        $definition = $this->pool->get('other_colors');

        $this->assertNotNull($definition);
        $this->assertSame('other_colors', $definition->getFamilyCode());
        $this->assertSame(FamilyLinkType::LINK_TYPE_OTHER_COLORS, $definition->getLinkTypeId());
        $this->assertSame('style_general', $definition->getGroupAttribute());
        $this->assertSame('color', $definition->getVariantAttribute());
        $this->assertTrue($definition->hasVariantAttribute());
        $this->assertSame(6, $definition->getMaxMembers());
        $this->assertTrue($definition->isDistinctVariants());
        $this->assertSame('Other colours', $definition->getLabel());
    }

    /**
     * "One chip per variant value" with no variant attribute would collapse the family to a single
     * chip on the first product that has no value — so the two settings are resolved together here
     * rather than left for the capper to discover.
     */
    public function testDistinctVariantsIsIgnoredWhenThereIsNoVariantAttribute(): void
    {
        $this->configure(
            enabled: true,
            familyEnabled: true,
            groupAttribute: 'style_general',
            variantAttribute: '',
            distinctVariants: true
        );

        $definition = $this->pool->get('similar');

        $this->assertNotNull($definition);
        $this->assertFalse($definition->hasVariantAttribute());
        $this->assertFalse($definition->isDistinctVariants());
    }

    public function testRunnableFamiliesComeBackKeyedInRenderOrder(): void
    {
        $this->configure(enabled: true, familyEnabled: true, groupAttribute: 'material');

        $this->assertSame(
            ['other_colors', 'other_sizes', 'similar'],
            array_keys($this->pool->getRunnable())
        );
    }

    public function testNothingIsRunnableWhileTheModuleIsOff(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $this->assertSame([], $this->pool->getRunnable());
    }

    private function configure(
        bool $enabled = true,
        bool $familyEnabled = true,
        string $groupAttribute = '',
        string $variantAttribute = '',
        int $maxMembers = 12,
        bool $distinctVariants = false,
        string $label = ''
    ): void {
        $this->config->method('isEnabled')->willReturn($enabled);
        $this->config->method('isFamilyEnabled')->willReturn($familyEnabled);
        $this->config->method('getGroupAttribute')->willReturn($groupAttribute);
        $this->config->method('getVariantAttribute')->willReturn($variantAttribute);
        $this->config->method('getMaxMembers')->willReturn($maxMembers);
        $this->config->method('isDistinctVariants')->willReturn($distinctVariants);
        $this->config->method('getLabel')->willReturn($label);
    }
}
