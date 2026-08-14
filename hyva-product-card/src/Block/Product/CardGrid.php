<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Block\Product;

use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Widget\Block\BlockInterface;

/**
 * Renderer three: a grid of cards, addressable from layout XML or from a widget instance.
 *
 * The block does one thing beyond loading products — it decides which of the *other two* renderers
 * draws each card. `render_mode` picks between the server template (cards are HTML, cacheable,
 * indexable) and the client template (cards are a JSON island, hydrated by Alpine, cheap to
 * re-sort). Both read the same {@see \Scr1be\HyvaProductCard\Model\Card\CardData}, which is the
 * only reason offering the choice is safe.
 *
 * Products come from an explicit SKU list. That is a smaller feature than a rules engine on
 * purpose: `Magento\CatalogWidget` already ships conditions-based product selection, and a second,
 * subtly different implementation of "which products" is not something a card module should own.
 */
class CardGrid extends AbstractProduct implements BlockInterface, IdentityInterface
{
    public const RENDER_MODE_SERVER = 'server';
    public const RENDER_MODE_CLIENT = 'client';

    private const DEFAULT_IMAGE_ID = 'category_page_grid';

    /**
     * A widget dropped on a CMS page is not a listing; it is a shelf. Past this many products the
     * merchant wants a category page, and the block should not be the thing that finds out by
     * loading four hundred rows into a CMS block.
     */
    private const MAX_PRODUCTS = 24;

    private ?array $products = null;

    /**
     * `AbstractProduct` rather than a plain `Template`, for one specific reason: Hyvä renders a
     * card by handing the *parent* block to `ProductListItem::getItemHtml()`, and its
     * `renderItemHtml()` calls `$parentBlock->getProductPrice($product)` before anything else —
     * annotated there as initialising the special price map on 2.4.8 and newer. A plain Template
     * has no such method; `DataObject::__call()` would quietly answer null and the call would do
     * nothing at all.
     */
    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
        private readonly Visibility $visibility,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return Product[]
     */
    public function getProducts(): array
    {
        if ($this->products !== null) {
            return $this->products;
        }

        $skus = $this->getSkuList();
        if ($skus === []) {
            return $this->products = [];
        }

        $collection = $this->createCollection($skus);

        // The SKU list is an ordering, not just a filter: a merchant who typed three SKUs meant
        // them in that order, and a collection returns them in entity-id order.
        $bySku = [];
        foreach ($collection as $product) {
            $bySku[(string) $product->getSku()] = $product;
        }

        $ordered = [];
        foreach ($skus as $sku) {
            if (isset($bySku[$sku])) {
                $ordered[] = $bySku[$sku];
            }
        }

        return $this->products = $ordered;
    }

    public function getRenderMode(): string
    {
        $mode = (string) $this->getData('render_mode');

        return $mode === self::RENDER_MODE_CLIENT ? self::RENDER_MODE_CLIENT : self::RENDER_MODE_SERVER;
    }

    public function getImageId(): string
    {
        $imageId = (string) $this->getData('image_id');

        return $imageId !== '' ? $imageId : self::DEFAULT_IMAGE_ID;
    }

    /**
     * The widget's own title doubles as its GA4 list name — a shelf called "Summer picks" should
     * report itself as "Summer picks" and not as whatever page it happens to sit on.
     */
    public function getListName(): ?string
    {
        $title = trim((string) $this->getData('title'));

        return $title !== '' ? $title : null;
    }

    /**
     * Widget blocks are cached by `Magento\Widget`'s own TTL, so the identities have to be right or
     * a price change never reaches the shelf.
     *
     * @return string[]
     */
    public function getIdentities(): array
    {
        $identities = [];
        foreach ($this->getProducts() as $product) {
            $identities[] = $product->getIdentities();
        }

        return $identities === [] ? [Product::CACHE_TAG] : array_merge(...$identities);
    }

    /**
     * @return string[]
     */
    private function getSkuList(): array
    {
        $raw = (string) $this->getData('skus');

        $skus = [];
        foreach (explode(',', $raw) as $candidate) {
            $sku = trim($candidate);
            if ($sku !== '') {
                $skus[$sku] = $sku;
            }
        }

        return array_slice(array_values($skus), 0, self::MAX_PRODUCTS);
    }

    /**
     * @param string[] $skus
     */
    private function createCollection(array $skus): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['name', 'small_image', 'small_image_label', 'image', 'image_label'])
            ->addAttributeToFilter('sku', ['in' => $skus])
            // Same visibility rule a category listing applies: a shelf must not surface products
            // the catalogue deliberately hides.
            ->setVisibility($this->visibility->getVisibleInCatalogIds())
            ->addStoreFilter($this->_storeManager->getStore())
            ->addMinimalPrice()
            ->addFinalPrice()
            ->addTaxPercents()
            ->setPageSize(self::MAX_PRODUCTS);

        return $collection;
    }
}
