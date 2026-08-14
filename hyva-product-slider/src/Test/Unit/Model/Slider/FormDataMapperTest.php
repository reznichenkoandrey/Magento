<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Test\Unit\Model\Slider;

use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Model\Breakpoints;
use Scr1be\HyvaProductSlider\Model\Slider\FormDataMapper;

class FormDataMapperTest extends TestCase
{
    private FormDataMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FormDataMapper(new Breakpoints());
    }

    public function testTheCategoryFieldBecomesTheSourceValue(): void
    {
        $slider = $this->mapper->applyToSlider(
            $this->formData(['source_type' => 'category', 'source_category' => '17']),
            new FakeSlider()
        );

        $this->assertSame('17', $slider->getSourceValue());
    }

    public function testTheAttributeSetFieldBecomesTheSourceValue(): void
    {
        $slider = $this->mapper->applyToSlider(
            $this->formData(['source_type' => 'attribute_set', 'source_attribute_set' => '9']),
            new FakeSlider()
        );

        $this->assertSame('9', $slider->getSourceValue());
    }

    public function testTheSkuFieldBecomesTheSourceValue(): void
    {
        $slider = $this->mapper->applyToSlider(
            $this->formData(['source_type' => 'manual', 'source_skus' => "24-MB01\n24-WB07"]),
            new FakeSlider()
        );

        $this->assertSame("24-MB01\n24-WB07", $slider->getSourceValue());
    }

    public function testAnArgumentFreeSourceStoresNoArgument(): void
    {
        // The merchandiser tried Category first and then switched to Bestsellers. The category id
        // must not survive that switch, or the slider carries an argument its source never reads and
        // a future source might.
        $slider = $this->mapper->applyToSlider(
            $this->formData(['source_type' => 'bestsellers', 'source_category' => '17']),
            new FakeSlider()
        );

        $this->assertNull($slider->getSourceValue());
    }

    public function testAnEmptyArgumentIsStoredAsNullRatherThanAnEmptyString(): void
    {
        $slider = $this->mapper->applyToSlider(
            $this->formData(['source_type' => 'category', 'source_category' => '  ']),
            new FakeSlider()
        );

        $this->assertNull($slider->getSourceValue());
    }

    public function testSlideCountsAreClampedOnTheWayIn(): void
    {
        $slider = $this->mapper->applyToSlider(
            $this->formData(['slides_mobile' => '0', 'slides_wide' => '99']),
            new FakeSlider()
        );

        $counts = $slider->getSlidesPerBreakpoint();

        $this->assertSame(Breakpoints::MIN_SLIDES, $counts[Breakpoints::MOBILE]);
        $this->assertSame(Breakpoints::MAX_SLIDES, $counts[Breakpoints::WIDE]);
    }

    public function testTitleAndIdentifierAreTrimmed(): void
    {
        $slider = $this->mapper->applyToSlider(
            $this->formData(['title' => '  New In  ', 'identifier' => ' home-new ']),
            new FakeSlider()
        );

        $this->assertSame('New In', $slider->getTitle());
        $this->assertSame('home-new', $slider->getIdentifier());
    }

    public function testTheRoundTripKeepsTheArgumentInItsOwnField(): void
    {
        // The direction that is easy to forget: a value that saved correctly must come back into the
        // control it was typed into, or editing a slider silently empties it.
        $stored = $this->mapper->applyToSlider(
            $this->formData(['source_type' => 'category', 'source_category' => '17']),
            new FakeSlider()
        );

        $formData = $this->mapper->toFormData($stored);

        $this->assertSame('17', $formData[FormDataMapper::FIELD_SOURCE_CATEGORY]);
        $this->assertNull($formData[FormDataMapper::FIELD_SOURCE_SKUS]);
        $this->assertNull($formData[FormDataMapper::FIELD_SOURCE_ATTRIBUTE_SET]);
    }

    public function testTheRoundTripKeepsTheSlideCounts(): void
    {
        $stored = $this->mapper->applyToSlider(
            $this->formData(['slides_desktop' => '3']),
            new FakeSlider()
        );

        $this->assertSame(3, $this->mapper->toFormData($stored)[SliderInterface::SLIDES_DESKTOP]);
    }

    public function testBooleansRoundTripAsTheIntegersTheFormElementsUse(): void
    {
        $stored = $this->mapper->applyToSlider(
            $this->formData(['is_active' => '1', 'autoplay' => '0', 'show_social_proof' => '1']),
            new FakeSlider()
        );

        $formData = $this->mapper->toFormData($stored);

        $this->assertSame(1, $formData[SliderInterface::IS_ACTIVE]);
        $this->assertSame(0, $formData[SliderInterface::AUTOPLAY]);
        $this->assertSame(1, $formData[SliderInterface::SHOW_SOCIAL_PROOF]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function formData(array $overrides = []): array
    {
        return array_merge(
            [
                'title' => 'New In',
                'identifier' => 'home-new',
                'is_active' => '1',
                'source_type' => 'new',
                'product_limit' => '12',
                'slides_mobile' => '1',
                'slides_tablet' => '2',
                'slides_desktop' => '4',
                'slides_wide' => '5',
                'autoplay' => '0',
                'autoplay_delay' => '5000',
                'is_loop' => '0',
                'show_social_proof' => '0',
                'store_id' => ['1'],
            ],
            $overrides
        );
    }
}
