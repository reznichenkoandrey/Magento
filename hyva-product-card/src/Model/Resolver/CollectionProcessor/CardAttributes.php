<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model\Resolver\CollectionProcessor;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Product\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\GraphQl\Model\Query\ContextInterface;

/**
 * Loads the attributes the card resolvers read, on every path that builds a product collection.
 *
 * A resolver-backed field is invisible to the collection: nothing in `card_badges` tells the query
 * builder that the badge decision needs `news_from_date` and a working price pool. Without this the
 * fields resolve to empty on some queries and populate on others, depending entirely on which
 * *other* fields the client happened to select — the worst kind of bug, because it looks like a
 * client-side mistake.
 *
 * `CollectionProcessorInterface` is the right seam because both product entry points run it:
 * `DataProvider\Product::getList()` (the filter/category path) and `DataProvider\ProductSearch::
 * getList()` (the full-text path) each call `$this->collectionPreProcessor->process(...)` before
 * `load()`. Registering here rather than in a resolver also means the attributes arrive in the one
 * `SELECT` that was going to run anyway.
 *
 * A second, less obvious job: core's `AttributeProcessor` runs over the *same* `$attributeNames`
 * array and calls `Collection::addAttributeToSelect()` on every entry it does not recognise, and
 * `Eav\Model\Entity\Collection\AbstractCollection::addAttributeToSelect()` throws
 * "The %1 attribute requested is invalid" for a code that is not an attribute. `card_badges` is not
 * an attribute. That is why `etc/graphql/di.xml` also registers the three field names in
 * `AttributeProcessor::$fieldToAttributeMap` — mapped to empty arrays, so core stops treating them
 * as attribute codes and this class stays the only place that decides what they actually need.
 */
class CardAttributes implements CollectionProcessorInterface
{
    private const FIELD_BADGES = 'card_badges';
    private const FIELD_MEDIA = 'card_media';

    /**
     * The badge decision compares the *rendered* final price against the regular price, which the
     * product's price model computes from these attributes. Catalogue price rules need no columns
     * here — they are applied by an observer during that computation.
     */
    private const BADGE_ATTRIBUTES = [
        'news_from_date',
        'news_to_date',
        'price',
        'special_price',
        'special_from_date',
        'special_to_date',
    ];

    /**
     * `small_image` is the source of the ladder; `image` is the hover fallback when the gallery is
     * not loaded, and the labels are what becomes alt text.
     */
    private const MEDIA_ATTRIBUTES = [
        'small_image',
        'small_image_label',
        'image',
        'image_label',
        'name',
    ];

    public function process(
        Collection $collection,
        SearchCriteriaInterface $searchCriteria,
        array $attributeNames,
        ?ContextInterface $context = null
    ): Collection {
        if (in_array(self::FIELD_BADGES, $attributeNames, true)) {
            $collection->addAttributeToSelect(self::BADGE_ATTRIBUTES);
        }

        if (in_array(self::FIELD_MEDIA, $attributeNames, true)) {
            $collection->addAttributeToSelect(self::MEDIA_ATTRIBUTES);
        }

        // `qty_rules` needs no attribute at all — it reads the stock registry, which
        // Observer\PreloadQtyRules fills in one query on `catalog_product_collection_load_after`.
        // That observer is registered for the graphql area as well as the storefront.
        return $collection;
    }
}
