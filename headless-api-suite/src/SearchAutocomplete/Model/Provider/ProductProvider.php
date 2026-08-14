<?php
declare(strict_types=1);

namespace Scr1be\SearchAutocomplete\Model\Provider;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\ImageFactory;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\SearchAutocomplete\Api\SuggestionProviderInterface;
use Scr1be\SearchAutocomplete\Model\SuggestionRequest;

/**
 * Matching products, as cards an app can render without a second round trip.
 *
 * **The collection.** `$collectionFactory` is not the catalogue's default factory; di.xml points it
 * at a virtual type pinned to the `quick_search_container` search request. This matters and is easy
 * to get wrong: `Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection` defaults its
 * `searchRequestName` to `catalog_view_container`, and `Magento_CatalogSearch/etc/search_request.xml`
 * declares no `$search_term$` binding in that container — only `quick_search_container` has one. A
 * collection built with the default therefore accepts `addSearchFilter()` and then returns the
 * catalogue, unfiltered, with no error anywhere.
 *
 * **Why the type hint names the catalogue factory.** The obvious hint —
 * `Magento\CatalogSearch\Model\ResourceModel\Fulltext\CollectionFactory` — is not a class at all: it
 * is a *virtual type* declared in `Magento_CatalogSearch/etc/di.xml`, itself an alias of
 * `Magento\Catalog\Model\ResourceModel\Product\CollectionFactory` with `instanceName` set to the
 * fulltext collection. Virtual types exist only in the object manager's configuration, so naming one
 * in a constructor signature fails DI compilation. The hint therefore names the real (generated)
 * factory class, and di.xml supplies this module's own pinned virtual type in its place.
 *
 * **The price.** `addPriceData($groupId, $websiteId)` is called with both arguments rather than
 * neither. Core's no-argument form falls back to `$this->_customerSession->getCustomerGroupId()`,
 * and a GraphQL request authenticated by a bearer token has no storefront session to answer that.
 */
class ProductProvider implements SuggestionProviderInterface
{
    /**
     * The image role rendered in the card. `small_image` is the role Magento's own listings use, and
     * `Magento\Catalog\Model\Product\Image::setDestinationSubdir()` takes the role name.
     */
    private const IMAGE_ROLE = 'small_image';

    /** The final price is below the regular one. */
    public const BADGE_DISCOUNT = 'discount';

    /** The price shown is a "from" price rather than the price of one thing. */
    public const BADGE_FROM_PRICE = 'from_price';

    /**
     * @param CollectionFactory $collectionFactory A factory for a quick-search-pinned collection.
     * @param ImageFactory $imageFactory
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ImageFactory $imageFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getSuggestions(SuggestionRequest $request): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($request->storeId);
        $collection->addAttributeToSelect(
            ['name', 'url_key', 'special_price', 'special_from_date', 'special_to_date', self::IMAGE_ROLE]
        );
        $collection->addSearchFilter($request->term);
        $collection->addPriceData($request->customerGroupId, $request->websiteId);
        $collection->setPageSize($request->limit);
        $collection->setCurPage(1);

        $currency = $this->storeManager->getStore($request->storeId)->getCurrentCurrencyCode();

        $suggestions = [];
        foreach ($collection as $product) {
            /** @var Product $product */
            $suggestions[] = [
                'sku' => (string)$product->getSku(),
                'name' => (string)$product->getName(),
                'url_key' => (string)$product->getData('url_key'),
                'image_url' => $this->imageUrl($product),
                'price' => $this->price($product, 'price'),
                'final_price' => $this->price($product, 'final_price'),
                'currency' => $currency,
                'badges' => $this->badges($product),
            ];
        }

        return $suggestions;
    }

    /**
     * The two badges that can be decided from the row that was already loaded.
     *
     * Deliberately not "new", "bestseller" or "low stock". Each of those needs another join or
     * another index, and an autocomplete that costs four queries per keystroke is not autocomplete.
     * A discount is a comparison of two columns the price index already returned, and the product
     * type is a column on `catalog_product_entity`.
     *
     * `Magento\Catalog\Model\Product\Type` declares `simple`, `bundle` and `virtual` and nothing
     * else — `configurable` lives in `Magento_ConfigurableProduct`. Only the constant this module
     * can see without taking a dependency on that module is used, so a configurable product gets no
     * from-price badge. That is the honest trade, and it is one line to change in a project that
     * already depends on the module.
     *
     * @param Product $product
     * @return string[]
     */
    private function badges(Product $product): array
    {
        $badges = [];

        $price = $this->price($product, 'price');
        $finalPrice = $this->price($product, 'final_price');
        if ($price !== null && $finalPrice !== null && $finalPrice < $price) {
            $badges[] = self::BADGE_DISCOUNT;
        }

        if ($product->getTypeId() === Type::TYPE_BUNDLE) {
            $badges[] = self::BADGE_FROM_PRICE;
        }

        return $badges;
    }

    /**
     * @param Product $product
     * @param string $field
     * @return float|null
     */
    private function price(Product $product, string $field): ?float
    {
        $value = $product->getData($field);

        return $value === null || $value === '' ? null : (float)$value;
    }

    /**
     * Built the way core's GraphQL media resolver builds it.
     *
     * `Magento\CatalogGraphQl\Model\Resolver\Product\MediaGallery\Url::getImageUrl()` does
     * `ImageFactory::create()->setDestinationSubdir($role)->setBaseFile($path)->getUrl()`, which is
     * the route that works in the graphql area — `Magento\Catalog\Helper\Image` wants a design theme
     * that is not set up there.
     *
     * @param Product $product
     * @return string|null
     */
    private function imageUrl(Product $product): ?string
    {
        $path = $product->getData(self::IMAGE_ROLE);
        if (!is_string($path) || $path === '' || $path === 'no_selection') {
            return null;
        }

        $image = $this->imageFactory->create();
        $image->setDestinationSubdir(self::IMAGE_ROLE);
        $image->setBaseFile($path);

        return $image->isBaseFilePlaceholder() ? null : $image->getUrl();
    }
}
