<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Source\SourceInterface;
use Magento\Swatches\Helper\Data as SwatchHelper;
use Magento\Swatches\Helper\Media as SwatchMedia;
use Magento\Swatches\Model\Swatch;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Model\FamilyDefinition;
use Scr1be\ProductFamilies\Model\FamilyDefinitionPool;
use Scr1be\ProductFamilies\Model\FamilyLinkType;
use Scr1be\ProductFamilies\Model\ResourceModel\FamilyLinkReader;
use Scr1be\ProductFamilies\ViewModel\ProductFamilies;

class ProductFamiliesTest extends TestCase
{
    private FamilyDefinitionPool&MockObject $definitionPool;
    private FamilyLinkReader&MockObject $linkReader;
    private CollectionFactory&MockObject $collectionFactory;
    private EavConfig&MockObject $eavConfig;
    private SwatchHelper&MockObject $swatchHelper;
    private SwatchMedia&MockObject $swatchMedia;
    private ProductFamilies $viewModel;

    protected function setUp(): void
    {
        $this->definitionPool = $this->createMock(FamilyDefinitionPool::class);
        $this->linkReader = $this->createMock(FamilyLinkReader::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->eavConfig = $this->createMock(EavConfig::class);
        $this->swatchHelper = $this->createMock(SwatchHelper::class);
        $this->swatchMedia = $this->createMock(SwatchMedia::class);

        $this->viewModel = new ProductFamilies(
            $this->definitionPool,
            $this->linkReader,
            $this->collectionFactory,
            $this->eavConfig,
            $this->swatchHelper,
            $this->swatchMedia
        );
    }

    public function testNothingIsReadWhileNoFamilyIsRunnable(): void
    {
        $this->definitionPool->method('getRunnable')->willReturn([]);
        $this->linkReader->expects($this->never())->method('getLinkedProductIds');

        $this->assertSame([], $this->viewModel->getRows($this->product(1)));
    }

    public function testBuildsAColourRowFromAVisualSwatch(): void
    {
        $this->givenColourFamily();
        $this->linkReader->method('getLinkedProductIds')->willReturn([2, 3]);
        $this->givenMembers([
            $this->member(2, 'Hoodie Black', ['color' => '15'], 'https://shop.test/black'),
            $this->member(3, 'Hoodie Red', ['color' => '16'], 'https://shop.test/red'),
        ]);
        $this->swatchHelper->method('getSwatchesByOptionsId')->willReturn([
            15 => ['type' => Swatch::SWATCH_TYPE_VISUAL_COLOR, 'value' => '#000000'],
            16 => ['type' => Swatch::SWATCH_TYPE_VISUAL_COLOR, 'value' => '#ff0000'],
        ]);
        $this->givenOptionLabels(['15' => 'Black', '16' => 'Red']);

        $rows = $this->viewModel->getRows($this->product(1));

        $this->assertCount(1, $rows);
        $this->assertSame('other_colors', $rows[0]['code']);
        $this->assertSame('Other colours', $rows[0]['label']);
        $this->assertSame(
            [
                [
                    'product_id' => 2,
                    'url' => 'https://shop.test/black',
                    'name' => 'Hoodie Black',
                    'label' => 'Black',
                    'swatch_type' => 'color',
                    'swatch_value' => '#000000',
                ],
                [
                    'product_id' => 3,
                    'url' => 'https://shop.test/red',
                    'name' => 'Hoodie Red',
                    'label' => 'Red',
                    'swatch_type' => 'color',
                    'swatch_value' => '#ff0000',
                ],
            ],
            $rows[0]['chips']
        );
    }

    /**
     * The colour value is written straight into a `style` attribute, so anything that is not the
     * `#rrggbb` the admin colour picker produces is refused rather than escaped and hoped for.
     *
     * @dataProvider unusableColourValues
     */
    public function testARejectedColourFallsBackToATextChip(string $value): void
    {
        $this->givenColourFamily();
        $this->linkReader->method('getLinkedProductIds')->willReturn([2]);
        $this->givenMembers([$this->member(2, 'Hoodie', ['color' => '15'], 'https://shop.test/h')]);
        $this->swatchHelper->method('getSwatchesByOptionsId')->willReturn([
            15 => ['type' => Swatch::SWATCH_TYPE_VISUAL_COLOR, 'value' => $value],
        ]);
        $this->givenOptionLabels(['15' => 'Black']);

        $chip = $this->viewModel->getRows($this->product(1))[0]['chips'][0];

        $this->assertSame('text', $chip['swatch_type']);
        $this->assertSame('', $chip['swatch_value']);
        $this->assertSame('Black', $chip['label'], 'the option label still names the chip');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableColourValues(): array
    {
        return [
            'shorthand hex' => ['#f00'],
            'named colour' => ['red'],
            'a declaration smuggled into the value' => ['#000; background-image: url(//evil)'],
            'not a colour at all' => ['url(//evil)'],
        ];
    }

    public function testAnImageSwatchResolvesThroughTheMediaHelper(): void
    {
        $this->givenColourFamily();
        $this->linkReader->method('getLinkedProductIds')->willReturn([2]);
        $this->givenMembers([$this->member(2, 'Hoodie', ['color' => '15'], 'https://shop.test/h')]);
        $this->swatchHelper->method('getSwatchesByOptionsId')->willReturn([
            15 => ['type' => Swatch::SWATCH_TYPE_VISUAL_IMAGE, 'value' => '/t/w/tweed.png'],
        ]);
        $this->givenOptionLabels(['15' => 'Tweed']);
        $this->swatchMedia->expects($this->once())
            ->method('getSwatchAttributeImage')
            ->with(Swatch::SWATCH_IMAGE_NAME, '/t/w/tweed.png')
            ->willReturn('https://shop.test/media/attribute/swatch/swatch_image/30x20/t/w/tweed.png');

        $chip = $this->viewModel->getRows($this->product(1))[0]['chips'][0];

        $this->assertSame('image', $chip['swatch_type']);
        $this->assertSame(
            'https://shop.test/media/attribute/swatch/swatch_image/30x20/t/w/tweed.png',
            $chip['swatch_value']
        );
    }

    /**
     * The link table is allowed to be a step behind the catalogue — nothing indexes it and the
     * reconcile is nightly. The page is not: a product disabled since the last run is dropped here
     * rather than rendered as a dead chip.
     */
    public function testAMemberTheCollectionDidNotReturnIsDroppedFromTheRow(): void
    {
        $this->givenColourFamily();
        $this->linkReader->method('getLinkedProductIds')->willReturn([2, 3]);
        $this->givenMembers([$this->member(2, 'Hoodie', ['color' => '15'], 'https://shop.test/h')]);
        $this->swatchHelper->method('getSwatchesByOptionsId')->willReturn([]);
        $this->givenOptionLabels(['15' => 'Black']);

        $this->assertCount(1, $this->viewModel->getRows($this->product(1))[0]['chips']);
    }

    public function testAFamilyWhoseMembersAllVanishedRendersNoRowAtAll(): void
    {
        $this->givenColourFamily();
        $this->linkReader->method('getLinkedProductIds')->willReturn([2]);
        $this->givenMembers([]);
        $this->swatchHelper->method('getSwatchesByOptionsId')->willReturn([]);

        $this->assertSame([], $this->viewModel->getRows($this->product(1)));
    }

    /**
     * A family with no variant attribute — "similar products" — has no option to label the chip
     * with, so the product name is the label and no swatch lookup happens at all.
     */
    public function testAFamilyWithoutAVariantAttributeLabelsChipsWithTheProductName(): void
    {
        $this->definitionPool->method('getRunnable')->willReturn([
            'similar' => new FamilyDefinition(
                'similar',
                FamilyLinkType::LINK_TYPE_SIMILAR,
                'style_general',
                '',
                8,
                false,
                'Similar products'
            ),
        ]);
        $this->linkReader->method('getLinkedProductIds')->willReturn([2]);
        $this->givenMembers([$this->member(2, 'Teton Pullover Hoodie', [], 'https://shop.test/teton')]);
        $this->swatchHelper->expects($this->never())->method('getSwatchesByOptionsId');

        $chip = $this->viewModel->getRows($this->product(1))[0]['chips'][0];

        $this->assertSame('Teton Pullover Hoodie', $chip['label']);
        $this->assertSame('text', $chip['swatch_type']);
    }

    public function testTheRowsAreBuiltOncePerProduct(): void
    {
        $this->givenColourFamily();
        $this->linkReader->expects($this->once())->method('getLinkedProductIds')->willReturn([2]);
        $this->givenMembers([$this->member(2, 'Hoodie', ['color' => '15'], 'https://shop.test/h')]);
        $this->swatchHelper->method('getSwatchesByOptionsId')->willReturn([]);
        $this->givenOptionLabels(['15' => 'Black']);

        $product = $this->product(1);

        $this->assertTrue($this->viewModel->hasRows($product));
        $this->assertNotSame([], $this->viewModel->getRows($product));
    }

    public function testAProductWithoutAnIdHasNoRows(): void
    {
        $this->definitionPool->expects($this->never())->method('getRunnable');

        $this->assertSame([], $this->viewModel->getRows($this->product(0)));
    }

    private function givenColourFamily(string $label = 'Other colours'): void
    {
        $this->definitionPool->method('getRunnable')->willReturn([
            'other_colors' => new FamilyDefinition(
                'other_colors',
                FamilyLinkType::LINK_TYPE_OTHER_COLORS,
                'style_general',
                'color',
                12,
                true,
                $label
            ),
        ]);
    }

    /**
     * @param array<int, Product&MockObject> $members
     */
    private function givenMembers(array $members): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('addStoreFilter')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('setVisibility')->willReturnSelf();
        $collection->method('addUrlRewrite')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($members));

        $this->collectionFactory->method('create')->willReturn($collection);
    }

    /**
     * @param array<string, string> $labels
     */
    private function givenOptionLabels(array $labels): void
    {
        $source = $this->createMock(SourceInterface::class);
        $source->method('getOptionText')->willReturnCallback(
            static fn (string $value): string => $labels[$value] ?? ''
        );

        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getAttributeId')->willReturn(93);
        $attribute->method('usesSource')->willReturn(true);
        $attribute->method('getSource')->willReturn($source);

        $this->eavConfig->method('getAttribute')->willReturn($attribute);
    }

    private function product(int $id): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }

    /**
     * @param array<string, string> $data
     */
    private function member(int $id, string $name, array $data, string $url): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getName')->willReturn($name);
        $product->method('getProductUrl')->willReturn($url);
        $product->method('getData')->willReturnCallback(
            static fn (string $key = '', $index = null) => $data[$key] ?? null
        );

        return $product;
    }
}
