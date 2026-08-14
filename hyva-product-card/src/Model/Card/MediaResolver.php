<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Model\Card;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Scr1be\HyvaProductCard\Model\Config;

/**
 * Builds the image ladder a responsive card needs, and decides who gets a hover image.
 *
 * Hyvä 1.4's stock list image template (`Magento_Catalog::product/list/image.phtml` in
 * `magento2-default-theme`) emits a single `src` with `width`, `height` and `loading="lazy"` —
 * correct and fast, but it hands a 360px file to a 640px retina card and a 360px file to a 180px
 * phone card alike. The ladder below is the only thing this module adds to that decision, and it
 * adds it in the one place all four renderers read from.
 *
 * The hover image is deliberately rationed. Each hover URL is a second resize target: a second
 * file generated on disk, cached, and fetched by the browser for a picture most shoppers never
 * see. `media/hover_max_products` caps how many cards on a page may pay for one.
 */
class MediaResolver
{
    /**
     * Ratio between the widest srcset rung and the `src` fallback. The fallback exists for browsers
     * that ignore `srcset`; handing those the widest rung wastes the bandwidth the ladder saved.
     */
    private const FALLBACK_WIDTH_INDEX = 0;

    /**
     * Gallery position of the hover candidate. Index 0 is the image the card already shows.
     */
    private const HOVER_GALLERY_INDEX = 1;

    /** @var int Cards served a hover image so far in this request. */
    private int $hoverBudgetSpent = 0;

    public function __construct(
        private readonly ImageHelper $imageHelper,
        private readonly Config $config
    ) {
    }

    /**
     * @param string $imageId A view.xml image id, e.g. `category_page_grid`.
     */
    public function resolve(Product $product, string $imageId): ImageSource
    {
        $storeId = (int) $product->getStoreId();
        $widths = $this->config->getSrcsetWidths($storeId);

        $rungs = [];
        foreach ($widths as $width) {
            $url = $this->urlAtWidth($product, $imageId, $width);
            if ($url !== '') {
                $rungs[$width] = $url;
            }
        }

        // Re-initialising the helper resets its state, so the intrinsic dimensions have to be read
        // from the same init that produced the fallback URL rather than from whatever ran last.
        $fallbackWidth = $widths[self::FALLBACK_WIDTH_INDEX] ?? null;
        $image = $this->imageHelper->init($product, $imageId);
        if ($fallbackWidth !== null) {
            $image = $image->resize($fallbackWidth);
        }

        return new ImageSource(
            (string) $image->getUrl(),
            (int) $image->getWidth(),
            (int) $image->getHeight(),
            (string) $image->getLabel(),
            $this->buildSrcset($rungs),
            $this->config->getSizesAttribute($storeId),
            $this->resolveHoverUrl($product, $imageId, $storeId)
        );
    }

    /**
     * Every card that asks spends from the same per-request budget, so the ceiling is a page-level
     * promise and not a per-block one. A block rendered twice does not double the budget.
     */
    public function hasHoverBudget(?int $storeId = null): bool
    {
        return $this->config->isHoverImageEnabled($storeId)
            && $this->hoverBudgetSpent < $this->config->getHoverImageCeiling($storeId);
    }

    /**
     * @param array<int, string> $rungs width => url
     */
    private function buildSrcset(array $rungs): string
    {
        if (count($rungs) < 2) {
            // A one-rung srcset is noise: it tells the browser nothing `src` did not already say.
            return '';
        }

        $parts = [];
        foreach ($rungs as $width => $url) {
            $parts[] = $url . ' ' . $width . 'w';
        }

        return implode(', ', $parts);
    }

    private function urlAtWidth(Product $product, string $imageId, int $width): string
    {
        return (string) $this->imageHelper->init($product, $imageId)->resize($width)->getUrl();
    }

    private function resolveHoverUrl(Product $product, string $imageId, int $storeId): ?string
    {
        if (!$this->hasHoverBudget($storeId)) {
            return null;
        }

        $candidate = $this->findHoverFile($product);
        if ($candidate === null) {
            return null;
        }

        $this->hoverBudgetSpent++;

        $width = $this->config->getSrcsetWidths($storeId)[self::FALLBACK_WIDTH_INDEX];

        return (string) $this->imageHelper
            ->init($product, $imageId, ['type' => 'image'])
            ->setImageFile($candidate)
            ->resize($width)
            ->getUrl();
    }

    /**
     * Two sources, in order of how much they cost.
     *
     * The gallery is authoritative but only readable when someone already paid for it — a listing
     * collection has no gallery unless `addMediaGalleryData()` ran, and calling that here would
     * turn one page render into a per-card query. So the gallery is used when present and the
     * `image` attribute is the fallback: on a Luma-shaped catalogue `image` and `small_image` are
     * usually different files, which is exactly the "other angle" a hover wants.
     */
    private function findHoverFile(Product $product): ?string
    {
        $gallery = $product->getData('media_gallery_images');
        if (is_iterable($gallery)) {
            $files = [];
            foreach ($gallery as $entry) {
                $file = is_object($entry) && method_exists($entry, 'getData') ? $entry->getData('file') : null;
                if (is_string($file) && $file !== '') {
                    $files[] = $file;
                }
            }
            if (isset($files[self::HOVER_GALLERY_INDEX])) {
                return $files[self::HOVER_GALLERY_INDEX];
            }
        }

        $base = (string) $product->getData('image');
        $small = (string) $product->getData('small_image');

        if ($base !== '' && $base !== 'no_selection' && $base !== $small) {
            return $base;
        }

        return null;
    }
}
