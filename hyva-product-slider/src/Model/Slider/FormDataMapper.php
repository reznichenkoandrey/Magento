<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\Slider;

use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Model\Breakpoints;
use Scr1be\HyvaProductSlider\Model\ProductSource\AttributeSetProducts;
use Scr1be\HyvaProductSlider\Model\ProductSource\CategoryProducts;
use Scr1be\HyvaProductSlider\Model\ProductSource\ManualSkus;

/**
 * The translation between the admin form and the entity, in both directions and in one place.
 *
 * The form cannot have a single `source_value` input, because the value means three different things
 * — a category id, an attribute set id, a SKU list — and each deserves its own control. The table
 * cannot have three columns, because exactly one of them is ever set and a schema that encodes "one
 * of these three, depending on a fourth" is a schema nobody can query.
 *
 * So the form has three fields, the row has one column, and this class is the seam. Keeping both
 * directions here is the point: a mapper that only knew how to save would let the edit form quietly
 * lose a value that saved correctly.
 */
class FormDataMapper
{
    public const FIELD_SOURCE_CATEGORY = 'source_category';
    public const FIELD_SOURCE_ATTRIBUTE_SET = 'source_attribute_set';
    public const FIELD_SOURCE_SKUS = 'source_skus';

    /** Which form field carries the argument, per source. */
    private const VALUE_FIELD_BY_SOURCE = [
        CategoryProducts::CODE => self::FIELD_SOURCE_CATEGORY,
        AttributeSetProducts::CODE => self::FIELD_SOURCE_ATTRIBUTE_SET,
        ManualSkus::CODE => self::FIELD_SOURCE_SKUS,
    ];

    public function __construct(private readonly Breakpoints $breakpoints)
    {
    }

    /**
     * @param array<string, mixed> $formData
     */
    public function applyToSlider(array $formData, SliderInterface $slider): SliderInterface
    {
        $sourceType = (string) ($formData[SliderInterface::SOURCE_TYPE] ?? '');

        $slider->setIdentifier(trim((string) ($formData[SliderInterface::IDENTIFIER] ?? '')))
            ->setTitle(trim((string) ($formData[SliderInterface::TITLE] ?? '')))
            ->setIsActive((bool) ($formData[SliderInterface::IS_ACTIVE] ?? false))
            ->setSourceType($sourceType)
            ->setSourceValue($this->readSourceValue($sourceType, $formData))
            ->setProductLimit((int) ($formData[SliderInterface::PRODUCT_LIMIT] ?? 0))
            ->setAutoplay((bool) ($formData[SliderInterface::AUTOPLAY] ?? false))
            ->setAutoplayDelay((int) ($formData[SliderInterface::AUTOPLAY_DELAY] ?? 0))
            ->setIsLoop((bool) ($formData[SliderInterface::IS_LOOP] ?? false))
            ->setShowSocialProof((bool) ($formData[SliderInterface::SHOW_SOCIAL_PROOF] ?? false))
            ->setStoreIds(array_map('intval', (array) ($formData[SliderInterface::STORE_ID] ?? [])));

        // Clamped rather than validated: an out-of-range slide count has an obviously correct
        // interpretation, and refusing the whole save over it helps nobody.
        return $slider->setSlidesPerBreakpoint(
            $this->breakpoints->normalise($this->readBreakpointCounts($formData))
        );
    }

    /**
     * @return array<string, mixed> Form field name => value.
     */
    public function toFormData(SliderInterface $slider): array
    {
        $data = [
            SliderInterface::SLIDER_ID => $slider->getSliderId(),
            SliderInterface::IDENTIFIER => $slider->getIdentifier(),
            SliderInterface::TITLE => $slider->getTitle(),
            SliderInterface::IS_ACTIVE => $slider->isActive() ? 1 : 0,
            SliderInterface::SOURCE_TYPE => $slider->getSourceType(),
            SliderInterface::PRODUCT_LIMIT => $slider->getProductLimit(),
            SliderInterface::AUTOPLAY => $slider->isAutoplay() ? 1 : 0,
            SliderInterface::AUTOPLAY_DELAY => $slider->getAutoplayDelay(),
            SliderInterface::IS_LOOP => $slider->isLoop() ? 1 : 0,
            SliderInterface::SHOW_SOCIAL_PROOF => $slider->isSocialProofEnabled() ? 1 : 0,
            SliderInterface::STORE_ID => $slider->getStoreIds(),
        ];

        foreach ($this->breakpoints->normalise($slider->getSlidesPerBreakpoint()) as $code => $count) {
            $data[(string) $this->breakpoints->getColumn($code)] = $count;
        }

        // Only the field belonging to the stored source is filled. Populating all three would make
        // the switcher show a category picker holding a SKU list.
        foreach (self::VALUE_FIELD_BY_SOURCE as $sourceType => $field) {
            $data[$field] = $slider->getSourceType() === $sourceType ? $slider->getSourceValue() : null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $formData
     */
    private function readSourceValue(string $sourceType, array $formData): ?string
    {
        $field = self::VALUE_FIELD_BY_SOURCE[$sourceType] ?? null;

        if ($field === null) {
            // An argument-free source stores no argument — not the argument left over from the
            // source the merchandiser tried first.
            return null;
        }

        $value = trim((string) ($formData[$field] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    private function readBreakpointCounts(array $formData): array
    {
        $counts = [];

        foreach ($this->breakpoints->getColumns() as $code => $column) {
            $counts[$code] = $formData[$column] ?? null;
        }

        return $counts;
    }
}
