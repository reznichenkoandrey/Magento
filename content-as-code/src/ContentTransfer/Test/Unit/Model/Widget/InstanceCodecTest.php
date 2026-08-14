<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Test\Unit\Model\Widget;

use PHPUnit\Framework\TestCase;
use Scr1be\ContentTransfer\Model\Slug;
use Scr1be\ContentTransfer\Model\StoreScope;
use Scr1be\ContentTransfer\Model\ThemeResolver;
use Scr1be\ContentTransfer\Model\Widget\InstanceCodec;

class InstanceCodecTest extends TestCase
{
    /**
     * The exact column names `Magento\Widget\Model\ResourceModel\Widget\Instance::_afterLoad()`
     * puts on the model — a raw row from `widget_instance_page`.
     */
    private const LOADED_ROW = [
        'page_id' => '7',
        'instance_id' => '3',
        'page_group' => 'all_pages',
        'layout_handle' => 'default',
        'block_reference' => 'content.top',
        'page_for' => 'all',
        'entities' => '',
        'page_template' => 'widget/static_block/default.phtml',
    ];

    public function testALoadedRowBecomesTheNeutralShape(): void
    {
        $this->assertSame(
            [
                [
                    'group' => 'all_pages',
                    'layout_handle' => 'default',
                    'block_reference' => 'content.top',
                    'for' => 'all',
                    'entities' => '',
                    'template' => 'widget/static_block/default.phtml',
                ],
            ],
            $this->codec()->toPortablePageGroups([self::LOADED_ROW])
        );
    }

    public function testThePlacementRowIdDoesNotSurviveCapture(): void
    {
        // `page_id` is the primary key of widget_instance_page, not a CMS page. Carrying it would
        // make the bundle point at a row on the source install.
        $captured = $this->codec()->toPortablePageGroups([self::LOADED_ROW])[0];

        $this->assertArrayNotHasKey('page_id', $captured);
        $this->assertArrayNotHasKey('instance_id', $captured);
    }

    public function testPlacementsAreSortedSoARecaptureIsByteIdentical(): void
    {
        $rows = [
            ['page_group' => 'all_pages', 'layout_handle' => 'default', 'block_reference' => 'sidebar'],
            ['page_group' => 'all_pages', 'layout_handle' => 'default', 'block_reference' => 'content.top'],
            ['page_group' => 'anchor_categories', 'layout_handle' => 'x', 'block_reference' => 'a'],
        ];

        $this->assertSame(
            ['content.top', 'sidebar', 'a'],
            array_column($this->codec()->toPortablePageGroups($rows), 'block_reference')
        );
    }

    public function testTheNeutralShapeBecomesTheShapeBeforeSaveExpects(): void
    {
        // Magento\Widget\Model\Widget\Instance::beforeSave() reads
        // $pageGroup[$pageGroup['page_group']] and silently drops the placement when that key is
        // absent — a widget that saves successfully and appears nowhere.
        $this->assertSame(
            [
                [
                    'page_group' => 'all_pages',
                    'all_pages' => [
                        'page_id' => '',
                        'layout_handle' => 'default',
                        'for' => 'all',
                        'block' => 'content.top',
                        'entities' => '',
                        'template' => 'widget/static_block/default.phtml',
                    ],
                ],
            ],
            $this->codec()->toAdminFormPageGroups(
                $this->codec()->toPortablePageGroups([self::LOADED_ROW])
            )
        );
    }

    public function testTheFormShapeCarriesAnEmptyPageIdSoPlacementsAreRecreated(): void
    {
        // An empty page_id keeps the placement out of `page_group_ids`, which makes the resource
        // model's `_afterSave()` delete the instance's existing rows before inserting these.
        $shaped = $this->codec()->toAdminFormPageGroups([
            ['group' => 'all_pages', 'layout_handle' => 'default', 'block_reference' => 'content.top'],
        ]);

        $this->assertSame('', $shaped[0]['all_pages']['page_id']);
    }

    public function testAPlacementWithNoGroupIsDroppedRatherThanShapedIntoGarbage(): void
    {
        $this->assertSame([], $this->codec()->toAdminFormPageGroups([['layout_handle' => 'default']]));
    }

    public function testEveryKeyBeforeSaveReadsIsPresentEvenWhenTheBundleOmittedIt(): void
    {
        // beforeSave() reads 'for', 'block' and 'entities' without an isset() guard, so a hand-
        // written bundle entry that leaves them out must not reach it half-populated.
        $shaped = $this->codec()->toAdminFormPageGroups([['group' => 'all_pages']]);

        $this->assertSame(
            ['page_id', 'layout_handle', 'for', 'block', 'entities', 'template'],
            array_keys($shaped[0]['all_pages'])
        );
    }

    public function testAPlacementLimitedToSpecificEntitiesIsCalledOut(): void
    {
        $codec = $this->codec();
        $groups = $codec->toPortablePageGroups([
            [
                'page_group' => 'anchor_categories',
                'layout_handle' => 'catalog_category_view_type_layered',
                'block_reference' => 'content',
                'page_for' => 'specific',
                'entities' => '3,11',
                'page_template' => '',
            ],
        ]);

        $this->assertSame('specific', $groups[0]['for']);
        $this->assertSame('3,11', $groups[0]['entities']);
    }

    public function testTheTypeSlugIsTheWidgetClassBasename(): void
    {
        $codec = $this->codec();

        $this->assertSame('block', $codec->typeSlug('Magento\Cms\Block\Widget\Block'));
        $this->assertSame('productslist', $codec->typeSlug('\Magento\CatalogWidget\Block\Product\ProductsList'));
    }

    private function codec(): InstanceCodec
    {
        return new InstanceCodec(
            $this->createMock(StoreScope::class),
            $this->createMock(ThemeResolver::class),
            new Slug()
        );
    }
}
