<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Model;

use Magento\Catalog\Model\Product\Link;
use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Model\FamilyLinkType;

class FamilyLinkTypeTest extends TestCase
{
    private FamilyLinkType $linkTypes;

    protected function setUp(): void
    {
        $this->linkTypes = new FamilyLinkType();
    }

    public function testTheRenderOrderIsTheDeclaredOrder(): void
    {
        $this->assertSame(['other_colors', 'other_sizes', 'similar'], $this->linkTypes->getFamilyCodes());
    }

    public function testEveryReservedIdIsUniqueAndEveryCodeIsDistinct(): void
    {
        $reserved = $this->linkTypes->getReservedTypes();

        $this->assertCount(3, $reserved);
        $this->assertCount(3, array_unique(array_values($reserved)));
    }

    /**
     * The reservation is a bet against core's own ids. `Magento\Catalog\Model\Product\Link` takes 1,
     * 4 and 5; `Magento\GroupedProduct\Model\ResourceModel\Product\Link::LINK_TYPE_GROUPED` takes 3,
     * spelled out below rather than imported so the test does not drag in a module this one does not
     * depend on. This case exists so that a future edit to the constants cannot quietly walk into
     * one of them.
     */
    public function testNoReservedIdCollidesWithACoreLinkType(): void
    {
        $groupedProductLinkTypeId = 3;

        $core = [
            Link::LINK_TYPE_RELATED,
            Link::LINK_TYPE_UPSELL,
            Link::LINK_TYPE_CROSSSELL,
            $groupedProductLinkTypeId,
        ];

        foreach (array_keys($this->linkTypes->getReservedTypes()) as $reservedId) {
            $this->assertNotContains($reservedId, $core, sprintf('link type id %d is core\'s', $reservedId));
        }
    }

    /**
     * `catalog_product_link_type.code` is a varchar(32) in `Magento_Catalog`'s db_schema.xml, and a
     * silently truncated code would make the install patch's lookup-by-code miss on the second run.
     */
    public function testEveryCodeFitsTheColumn(): void
    {
        foreach ($this->linkTypes->getReservedTypes() as $code) {
            $this->assertLessThanOrEqual(32, strlen($code));
        }
    }

    public function testResolvesIdsAndCodesByFamilyCode(): void
    {
        $this->assertSame(FamilyLinkType::LINK_TYPE_SIMILAR, $this->linkTypes->getLinkTypeId('similar'));
        $this->assertSame(FamilyLinkType::CODE_SIMILAR, $this->linkTypes->getLinkTypeCode('similar'));
    }

    public function testAnUnknownFamilyCodeIsAProgrammingErrorNotAConfigurationOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->linkTypes->getLinkTypeId('nonsense');
    }
}
