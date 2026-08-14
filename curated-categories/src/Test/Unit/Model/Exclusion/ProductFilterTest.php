<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model\Exclusion;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\CuratedCategories\Model\Exclusion\ProductFilter;
use Scr1be\CuratedCategories\Model\Exclusion\Rule;
use Scr1be\CuratedCategories\Model\Exclusion\RuleSet;

class ProductFilterTest extends TestCase
{
    private CollectionFactory&MockObject $collectionFactory;
    private EavConfig&MockObject $eavConfig;
    private LoggerInterface&MockObject $logger;
    private ProductFilter $filter;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $this->eavConfig = $this->createMock(EavConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->filter = new ProductFilter($this->collectionFactory, $this->eavConfig, $this->logger);
    }

    public function testAnEmptyRuleSetSkipsTheQueryEntirely(): void
    {
        $this->collectionFactory->expects($this->never())->method('create');

        $this->assertSame([1, 2], $this->filter->apply([1, 2], new RuleSet([], RuleSet::MATCH_ANY)));
    }

    public function testNoCandidatesSkipsTheQueryEntirely(): void
    {
        $this->collectionFactory->expects($this->never())->method('create');

        $this->assertSame(
            [],
            $this->filter->apply([], new RuleSet([new Rule('color', Rule::OPERATOR_EQ, 'Blue')], RuleSet::MATCH_ANY))
        );
    }

    /**
     * The survivors come back in the order the source ranked them — the filter removes products, it
     * does not re-rank them.
     */
    public function testRemovesExcludedProductsAndPreservesRanking(): void
    {
        $this->givenAttributeExists('color');
        $this->givenProducts([
            30 => ['color' => 'Red'],
            10 => ['color' => 'Blue'],
            20 => ['color' => 'Green'],
        ]);

        $ruleSet = new RuleSet([new Rule('color', Rule::OPERATOR_EQ, 'Blue')], RuleSet::MATCH_ANY);

        $this->assertSame([20, 30], $this->filter->apply([20, 10, 30], $ruleSet));
    }

    /**
     * `addAttributeToSelect()` raises a LocalizedException deep in the EAV resource for a code that
     * does not exist, and one typo in the admin form would otherwise take the whole hourly reconcile
     * with it.
     */
    public function testAMistypedAttributeIsLoggedAndTheRunSurvives(): void
    {
        $this->eavConfig->method('getAttribute')->willReturn(null);
        $this->collectionFactory->expects($this->never())->method('create');
        $this->logger->expects($this->once())->method('warning')->with($this->stringContains('colour'));

        $ruleSet = new RuleSet([new Rule('colour', Rule::OPERATOR_EQ, 'Blue')], RuleSet::MATCH_ANY);

        $this->assertSame([1, 2], $this->filter->apply([1, 2], $ruleSet));
    }

    public function testAnAttributeLookupThatThrowsIsTreatedAsUnknown(): void
    {
        $this->eavConfig->method('getAttribute')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('warning');

        $ruleSet = new RuleSet([new Rule('color', Rule::OPERATOR_EQ, 'Blue')], RuleSet::MATCH_ANY);

        $this->assertSame([1], $this->filter->apply([1], $ruleSet));
    }

    /**
     * Membership is global — the pivot has no store column — so the values that decide it are the
     * default-scope ones. Reading a store view's overrides would make the answer depend on which
     * store view happened to run the reconcile.
     */
    public function testValuesAreReadInTheDefaultScope(): void
    {
        $this->givenAttributeExists('color');

        $collection = $this->collection([10 => ['color' => 'Red']]);
        $collection->expects($this->once())
            ->method('setStoreId')
            ->with(Store::DEFAULT_STORE_ID)
            ->willReturnSelf();
        $this->collectionFactory->method('create')->willReturn($collection);

        $ruleSet = new RuleSet([new Rule('color', Rule::OPERATOR_EQ, 'Blue')], RuleSet::MATCH_ANY);

        $this->assertSame([10], $this->filter->apply([10], $ruleSet));
    }

    /**
     * A candidate whose row the collection did not return is evaluated against an empty value set,
     * which is what makes "is not" and "is none of" behave the same way for it as for a product with
     * the attribute genuinely unset.
     */
    public function testACandidateMissingFromTheCollectionIsEvaluatedAgainstNothing(): void
    {
        $this->givenAttributeExists('color');
        $this->givenProducts([]);

        $ruleSet = new RuleSet([new Rule('color', Rule::OPERATOR_NEQ, 'Blue')], RuleSet::MATCH_ANY);

        $this->assertSame([], $this->filter->apply([99], $ruleSet));
    }

    private function givenAttributeExists(string $code): void
    {
        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getAttributeId')->willReturn('7');

        $this->eavConfig->method('getAttribute')->with(Product::ENTITY, $code)->willReturn($attribute);
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function givenProducts(array $products): void
    {
        $this->collectionFactory->method('create')->willReturn($this->collection($products));
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    private function collection(array $products): Collection&MockObject
    {
        $items = [];
        foreach ($products as $productId => $data) {
            $product = $this->createMock(Product::class);
            $product->method('getId')->willReturn((string) $productId);
            $product->method('getData')
                ->willReturnCallback(static fn (string $key): mixed => $data[$key] ?? null);
            $items[] = $product;
        }

        $collection = $this->createMock(Collection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        return $collection;
    }
}
