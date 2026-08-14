<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Block;

use Hyva\Theme\ViewModel\ProductListItem;
use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Block\Product\ReviewRendererInterface;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Api\SliderRepositoryInterface;
use Scr1be\HyvaProductSlider\Model\Breakpoints;
use Scr1be\HyvaProductSlider\Model\Config;
use Scr1be\HyvaProductSlider\Model\Slider as SliderModel;
use Scr1be\HyvaProductSlider\Model\Slider\ProductProvider;

/**
 * One carousel, rendered server-side and cached like any other block.
 *
 * **Why it extends `AbstractProduct` and not `Template`.** Every slide is drawn by Hyvä's
 * `product_list_item` block, and `Hyva\Theme\ViewModel\ProductListItem::renderItemHtml()` opens with
 * `$parentBlock->getProductPrice($product)` — annotated there as initialising the special-price map on
 * 2.4.8 and newer. `AbstractProduct::getProductPrice()` is that method. A plain `Template` has no such
 * method, `DataObject::__call()` would answer null, and the failure would show up as wrong prices on
 * some products and not others.
 *
 * Rendering through that block rather than through a template of our own is the whole integration
 * strategy: whatever a storefront has decided a product card looks like — Hyvä's stock card, or the
 * one `scr1be/hyva-product-card` substitutes by repointing the same block — is what a slide looks
 * like, automatically and without this module depending on either.
 *
 * **What is cached and what is not.** The block output is cached: the markup, the cards, the prices.
 * The purchase line is not, because it changes every minute; it is fetched afterwards, keyed by
 * product id. That split is the reason a slider can carry live social proof without becoming
 * uncacheable — see `Controller\Proof\Index`.
 */
class Slider extends AbstractProduct implements IdentityInterface
{
    /**
     * Hyvä's own widget-grid image id, reused so a slide is dimensioned like every other card the
     * theme draws. It is declared in `magento2-default-theme/etc/view.xml` as a `small_image` entry.
     */
    public const IMAGE_ID = 'new_products_content_widget_grid';

    private const VIEW_MODE = 'grid';

    /**
     * Time-ranked sources — bestsellers, most viewed, recently bought — change without any product
     * being saved, so identities alone would never invalidate them. An hour is short enough that a
     * carousel is never visibly stale and long enough that it is a cache hit on every real page view.
     */
    private const DEFAULT_CACHE_LIFETIME = 3600;

    /**
     * A default, so a layout `<block>` that forgets the `template` attribute renders the slider
     * instead of an empty string. `Template::_construct()` overwrites it from `data['template']`
     * whenever one is supplied — by layout XML or by the widget's own template parameter.
     */
    protected $_template = 'Scr1be_HyvaProductSlider::slider.phtml';

    private ?SliderInterface $slider = null;

    private bool $sliderResolved = false;

    /** @var Product[]|null */
    private ?array $products = null;

    public function __construct(
        Context $context,
        private readonly SliderRepositoryInterface $sliderRepository,
        private readonly ProductProvider $productProvider,
        private readonly ProductListItem $productListItemViewModel,
        private readonly Breakpoints $breakpoints,
        private readonly Config $config,
        private readonly JsonHexTag $jsonSerializer,
        private readonly HttpContext $httpContext,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getIdentifier(): string
    {
        return trim((string) $this->getData('identifier'));
    }

    public function getSlider(): ?SliderInterface
    {
        if ($this->sliderResolved) {
            return $this->slider;
        }

        $this->sliderResolved = true;

        $identifier = $this->getIdentifier();
        if ($identifier === '' || !$this->config->isEnabled($this->getCurrentStoreId())) {
            return null;
        }

        try {
            $slider = $this->sliderRepository->getByIdentifier($identifier, $this->getCurrentStoreId());
        } catch (NoSuchEntityException) {
            // A layout handle or widget pointing at a slider that was deleted or unassigned from this
            // store renders nothing. It is a content mistake, not a server error.
            return null;
        }

        return $this->slider = $slider->isActive() ? $slider : null;
    }

    /**
     * @return Product[]
     */
    public function getProducts(): array
    {
        if ($this->products !== null) {
            return $this->products;
        }

        $slider = $this->getSlider();

        return $this->products = $slider === null
            ? []
            : $this->productProvider->getProducts($slider, $this->getCurrentStoreId());
    }

    /**
     * A carousel that cannot scroll is a row of products, and drawing arrows on it is a lie. The
     * comparison is against the *widest* configured breakpoint, because that is the layout where the
     * slider is least likely to overflow.
     */
    public function isScrollable(): bool
    {
        $slider = $this->getSlider();
        if ($slider === null) {
            return false;
        }

        return count($this->getProducts()) > $this->breakpoints->getWidest($slider->getSlidesPerBreakpoint());
    }

    public function getItemHtml(Product $product): string
    {
        return $this->productListItemViewModel->getItemHtml(
            $product,
            $this,
            self::VIEW_MODE,
            ReviewRendererInterface::SHORT_VIEW,
            self::IMAGE_ID,
            false
        );
    }

    /**
     * The per-breakpoint slide counts, as a `style` attribute value.
     *
     * A `style` attribute rather than a `<style>` element because an element would need a CSP hash
     * registered from inside a block that may be served from cache — the same trap
     * `scr1be/hyva-product-card` documents for its import map. And custom properties rather than
     * Tailwind classes because the counts are data: `lg:basis-1/7` cannot be produced by a build that
     * scans source files for class names.
     *
     * If a hardened `style-src` drops the attribute, `module.css` still defines all four variables,
     * so the slider falls back to the default column counts rather than to a broken layout.
     */
    public function getBreakpointStyle(): string
    {
        $slider = $this->getSlider();
        if ($slider === null) {
            return '';
        }

        $declarations = [];
        foreach ($this->breakpoints->normalise($slider->getSlidesPerBreakpoint()) as $code => $count) {
            $declarations[] = sprintf('%s:%d', $this->breakpoints->getCssVariable($code), $count);
        }

        return implode(';', $declarations);
    }

    /**
     * Behaviour the Alpine component needs, as one JSON island.
     *
     * Product ids travel in it because the proof fetch needs them and the DOM would otherwise have to
     * be scraped for them — a card's markup is Hyvä's, and reading ids out of it would couple this
     * module to a template it deliberately does not own.
     */
    public function getSliderConfigJson(): string
    {
        $slider = $this->getSlider();
        if ($slider === null) {
            return $this->jsonSerializer->serialize([]);
        }

        return $this->jsonSerializer->serialize(
            [
                'identifier' => $slider->getIdentifier(),
                'autoplay' => $slider->isAutoplay(),
                'autoplayDelay' => $slider->getAutoplayDelay(),
                'loop' => $slider->isLoop(),
                'socialProof' => $this->isSocialProofEnabled(),
                'proofUrl' => $this->getProofUrl(),
                'productIds' => array_map(
                    static fn (Product $product): int => (int) $product->getId(),
                    $this->getProducts()
                ),
            ]
        );
    }

    public function isSocialProofEnabled(): bool
    {
        $slider = $this->getSlider();

        return $slider !== null
            && $slider->isSocialProofEnabled()
            && $this->config->isSocialProofEnabled($this->getCurrentStoreId());
    }

    public function getProofUrl(): string
    {
        return $this->getUrl('scr1be_slider/proof/index');
    }

    /**
     * The DOM id the arrows, dots and track are scoped by. Prefixed rather than bare so that two
     * sliders on one page, or a slider next to unrelated markup, cannot collide.
     */
    public function getDomId(): string
    {
        return 'scr1be-slider-' . ($this->getSlider()?->getSliderId() ?? 0);
    }

    /**
     * @return string[]
     */
    public function getIdentities(): array
    {
        $slider = $this->getSlider();
        if ($slider === null) {
            return [];
        }

        // Three layers, and the first one is not redundant.
        //
        // `AbstractModel::afterSave()` calls `cleanModelCache()`, which cleans by `getCacheTags()` —
        // i.e. by the model's `$_cacheTag`, the *generic* `scr1be_slider`. A block tagged only
        // `scr1be_slider_3` would never be matched by that clean, and the merchandiser's edit would
        // sit behind an hour of block cache. The per-id tag is what the full page cache invalidates
        // on, because `PageCache\Observer\FlushCacheByTags` resolves tags through
        // `Cache\Tag\Strategy\Identifier`, which returns the model's `getIdentities()`.
        //
        // The catalogue-wide product tag covers everything else: a slider's membership is derived, so
        // a price change, a stock change or a new product can alter what it shows without the slider
        // row being touched at all.
        $identities = [
            SliderModel::CACHE_TAG,
            SliderModel::CACHE_TAG . '_' . $slider->getSliderId(),
            Product::CACHE_TAG,
        ];

        foreach ($this->getProducts() as $product) {
            $identities[] = Product::CACHE_TAG . '_' . $product->getId();
        }

        return array_values(array_unique($identities));
    }

    /**
     * @return array<int, int|string|null>
     */
    public function getCacheKeyInfo(): array
    {
        return [
            'SCR1BE_PRODUCT_SLIDER',
            $this->getIdentifier(),
            $this->getCurrentStoreId(),
            $this->getTemplate(),
            // The cached HTML contains rendered prices, so it is only reusable by visitors whose
            // prices are the same: same customer group, same tax context.
            (string) $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP),
            json_encode($this->httpContext->getValue('tax_rates') ?? []),
        ];
    }

    /**
     * Protected, matching `AbstractBlock::getCacheLifetime()` — it is called by `toHtml()`, never by
     * a template.
     *
     * @return int|bool
     */
    protected function getCacheLifetime()
    {
        $lifetime = $this->getData('cache_lifetime');

        // An empty string is what the widget form submits for a field the merchandiser left blank,
        // and casting it would produce a lifetime of zero — which is not "no override", it is a very
        // different caching decision.
        return $lifetime === null || $lifetime === '' ? self::DEFAULT_CACHE_LIFETIME : (int) $lifetime;
    }

    private function getCurrentStoreId(): int
    {
        return (int) $this->_storeManager->getStore()->getId();
    }
}
