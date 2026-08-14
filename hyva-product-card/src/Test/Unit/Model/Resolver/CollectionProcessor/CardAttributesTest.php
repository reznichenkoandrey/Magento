<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Test\Unit\Model\Resolver\CollectionProcessor;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Api\SearchCriteriaInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductCard\Model\Resolver\CollectionProcessor\CardAttributes;

class CardAttributesTest extends TestCase
{
    private Collection&MockObject $collection;
    private SearchCriteriaInterface&MockObject $searchCriteria;
    private CardAttributes $processor;

    protected function setUp(): void
    {
        $this->collection = $this->createMock(Collection::class);
        $this->searchCriteria = $this->createMock(SearchCriteriaInterface::class);
        $this->processor = new CardAttributes();
    }

    public function testBadgeAttributesArriveInTheOneSelectThatWasGoingToRunAnyway(): void
    {
        $this->collection->expects($this->once())
            ->method('addAttributeToSelect')
            ->with([
                'news_from_date',
                'news_to_date',
                'price',
                'special_price',
                'special_from_date',
                'special_to_date',
            ])
            ->willReturnSelf();

        $this->processor->process($this->collection, $this->searchCriteria, ['card_badges']);
    }

    public function testMediaAttributesAreAddedForTheMediaFieldOnly(): void
    {
        $this->collection->expects($this->once())
            ->method('addAttributeToSelect')
            ->with(['small_image', 'small_image_label', 'image', 'image_label', 'name'])
            ->willReturnSelf();

        $this->processor->process($this->collection, $this->searchCriteria, ['card_media']);
    }

    public function testQtyRulesNeedNoAttributeBecauseTheyAreNotAttributes(): void
    {
        // They come from the stock registry, which the load-after observer fills in one query.
        $this->collection->expects($this->never())->method('addAttributeToSelect');

        $this->processor->process($this->collection, $this->searchCriteria, ['qty_rules']);
    }

    public function testAQueryThatSelectsNoCardFieldPaysNothing(): void
    {
        $this->collection->expects($this->never())->method('addAttributeToSelect');

        $this->processor->process($this->collection, $this->searchCriteria, ['name', 'sku', 'price_range']);
    }

    public function testTheCollectionIsHandedBackForTheCompositeToChain(): void
    {
        $this->collection->method('addAttributeToSelect')->willReturnSelf();

        $this->assertSame(
            $this->collection,
            $this->processor->process($this->collection, $this->searchCriteria, ['card_badges', 'card_media'])
        );
    }
}
