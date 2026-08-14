<?php
declare(strict_types=1);

namespace Scr1be\SearchAutocomplete\Model\Provider;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Scr1be\SearchAutocomplete\Api\SuggestionProviderInterface;
use Scr1be\SearchAutocomplete\Model\SuggestionRequest;

/**
 * Matching categories.
 *
 * A `LIKE` against the category name rather than a search index, and that is the right call: the
 * fulltext index covers products, categories are a few hundred rows even on a large catalogue, and
 * standing up a second index for them would cost more to keep correct than the query costs to run.
 *
 * Two filters carry most of the value:
 *
 *  - `is_active = 1`, so a category a merchant has taken down does not reappear in a search box.
 *  - `level > 1`, which drops the invisible root. Magento's tree has the root category at level 1
 *    ("Default Category" on a Luma install), it is not a page anybody can visit, and a store whose
 *    root is called "Default Category" would otherwise suggest it for the query "def".
 */
class CategoryProvider implements SuggestionProviderInterface
{
    /**
     * Level 0 is the tree root, level 1 is a store's root category. Real categories start at 2.
     */
    private const FIRST_NAVIGABLE_LEVEL = 2;

    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    /**
     * @inheritDoc
     */
    public function getSuggestions(SuggestionRequest $request): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($request->storeId);
        $collection->addAttributeToSelect(['name', 'url_key', 'url_path']);
        $collection->addAttributeToFilter('is_active', 1);
        $collection->addAttributeToFilter('level', ['gteq' => self::FIRST_NAVIGABLE_LEVEL]);
        $collection->addAttributeToFilter('name', ['like' => '%' . $this->escapeLike($request->term) . '%']);
        $collection->setPageSize($request->limit);
        $collection->setCurPage(1);

        $suggestions = [];
        foreach ($collection as $category) {
            /** @var Category $category */
            $suggestions[] = [
                'id' => (int)$category->getId(),
                'name' => (string)$category->getName(),
                'url_path' => (string)($category->getData('url_path') ?: $category->getData('url_key')),
                'product_count' => (int)$category->getProductCount(),
            ];
        }

        return $suggestions;
    }

    /**
     * Escape the wildcards a shopper can type.
     *
     * Without this, a search for `%` matches every category and a search for `_` matches all of them
     * with a one-character name. Neither is an injection — the value is still bound — but both are a
     * search box that returns nonsense for a character people do type.
     *
     * @param string $term
     * @return string
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
