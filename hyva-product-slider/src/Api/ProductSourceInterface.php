<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Api;

use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;

/**
 * One way of choosing which products a slider shows.
 *
 * A source returns **ids in the order it wants them rendered**, and nothing else. It does not load
 * products, does not apply visibility or stock rules and does not know what a slide looks like —
 * {@see \Scr1be\HyvaProductSlider\Model\Slider\ProductProvider} does all three, once, for every
 * source. Nine implementations that each build their own product collection would be nine places
 * for "disabled products leaked into a carousel" to happen.
 *
 * The ids a source returns are candidates: the provider filters them and keeps the survivors in the
 * order given, which is why sources are asked for more ids than the slider will show.
 */
interface ProductSourceInterface
{
    /**
     * The stored `source_type` value. Must match the key this source is registered under in di.xml.
     */
    public function getCode(): string;

    /**
     * Shown in the admin form's source dropdown.
     */
    public function getLabel(): string;

    /**
     * False hides the source from the admin form and makes it resolve to no products — used by the
     * sources whose data is produced by a module that can be switched off.
     */
    public function isAvailable(): bool;

    /**
     * @param int $limit Number of candidate ids to return, already inflated by the provider's
     *                   over-fetch factor. Implementations must honour it: it is the only bound on
     *                   the query.
     * @return int[] Product ids, most relevant first, de-duplicated.
     */
    public function getProductIds(SliderInterface $slider, int $storeId, int $limit): array;

    /**
     * Reject an argument this source cannot work with, at save time.
     *
     * It lives on the source because the source is the only thing that knows what `source_value`
     * means — a category id, an attribute set id, a SKU list or nothing at all. A central map of
     * "which types need a value" would be a second place to edit whenever a tenth source is added,
     * and the kind of place that gets forgotten.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function validateSourceValue(?string $sourceValue): void;
}
