<?php
declare(strict_types=1);

namespace Scr1be\SearchAutocomplete\Test\Unit\Model\Provider;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\SearchAutocomplete\Model\Provider\CategoryProvider;
use Scr1be\SearchAutocomplete\Model\SuggestionRequest;

/**
 * The category provider is four filters, and three of them are the ones people leave out.
 */
class CategoryProviderTest extends TestCase
{
    /**
     * @var array<int, array{0: string, 1: mixed}>
     */
    private array $filters = [];

    /**
     * What the collection iterates. A test that cares about the mapped output assigns to this rather
     * than re-stubbing `getIterator`: PHPUnit keeps the *first* stub registered for a method, so a
     * second `->method('getIterator')` in a test body is silently ignored and the collection keeps
     * returning whatever setUp() said.
     *
     * @var array<int, Category&MockObject>
     */
    private array $items = [];

    private Collection&MockObject $collection;
    private CategoryProvider $provider;

    protected function setUp(): void
    {
        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('addAttributeToFilter')->willReturnCallback(
            function ($attribute, $condition = null) {
                $this->filters[] = [$attribute, $condition];

                return $this->collection;
            }
        );
        $this->collection->method('getIterator')->willReturnCallback(
            fn (): \ArrayIterator => new \ArrayIterator($this->items)
        );

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($this->collection);

        $this->provider = new CategoryProvider($factory);
    }

    public function testFiltersToActiveNavigableCategoriesMatchingTheTerm(): void
    {
        $this->provider->getSuggestions(new SuggestionRequest('tops', 1, 1, 0, 8));

        $this->assertSame(
            [
                ['is_active', 1],
                ['level', ['gteq' => 2]],
                ['name', ['like' => '%tops%']],
            ],
            $this->filters
        );
    }

    /**
     * `%` and `_` are characters people type. Without escaping, a search for "%" matches every
     * category on the store and a search for "_" matches every one-character name — not an
     * injection, but a search box that answers nonsense.
     */
    public function testEscapesLikeWildcardsInTheTerm(): void
    {
        $this->provider->getSuggestions(new SuggestionRequest('50%_off\\', 1, 1, 0, 8));

        $this->assertSame(['like' => '%50\\%\\_off\\\\%'], $this->filters[2][1]);
    }

    public function testRespectsTheLimitAndTheStore(): void
    {
        $this->collection->expects($this->once())->method('setStoreId')->with(7);
        $this->collection->expects($this->once())->method('setPageSize')->with(5);
        $this->collection->expects($this->once())->method('setCurPage')->with(1);

        $this->provider->getSuggestions(new SuggestionRequest('tops', 7, 1, 0, 5));
    }

    /**
     * `url_path` is only populated when the "use categories path for product URLs" setting is on, so
     * a client that relied on it alone would get an empty link on half the stores in the world.
     */
    public function testFallsBackToTheUrlKeyWhenThereIsNoUrlPath(): void
    {
        $this->items = [$this->category(12, 'Tops', null, 'tops-women', 42)];

        $suggestions = $this->provider->getSuggestions(new SuggestionRequest('tops', 1, 1, 0, 8));

        $this->assertSame(
            [['id' => 12, 'name' => 'Tops', 'url_path' => 'tops-women', 'product_count' => 42]],
            $suggestions
        );
    }

    private function category(
        int $id,
        string $name,
        ?string $urlPath,
        string $urlKey,
        int $productCount
    ): Category&MockObject {
        $data = ['url_path' => $urlPath, 'url_key' => $urlKey];

        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getName')->willReturn($name);
        $category->method('getProductCount')->willReturn($productCount);
        $category->method('getData')->willReturnCallback(
            static fn ($key = '', $index = null) => $data[$key] ?? null
        );

        return $category;
    }
}
