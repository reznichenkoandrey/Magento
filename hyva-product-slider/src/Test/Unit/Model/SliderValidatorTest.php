<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Api\ProductSourceInterface;
use Scr1be\HyvaProductSlider\Model\Breakpoints;
use Scr1be\HyvaProductSlider\Model\ProductSource\Pool;
use Scr1be\HyvaProductSlider\Model\SliderValidator;

class SliderValidatorTest extends TestCase
{
    private ProductSourceInterface&MockObject $source;
    private SliderValidator $validator;

    protected function setUp(): void
    {
        $this->source = $this->createMock(ProductSourceInterface::class);
        $this->source->method('isAvailable')->willReturn(true);

        $this->validator = new SliderValidator(new Pool(['new' => $this->source]), new Breakpoints());
    }

    public function testAValidSliderPasses(): void
    {
        $this->source->expects($this->once())->method('validateSourceValue');

        $this->validator->validate($this->slider());

        $this->addToAssertionCount(1);
    }

    public function testAnEmptyTitleIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Enter a title');

        $this->validator->validate($this->slider(['title' => '   ']));
    }

    /**
     * @dataProvider invalidIdentifiers
     */
    public function testAnIdentifierThatCannotSurviveLayoutXmlIsRejected(string $identifier): void
    {
        $this->expectException(LocalizedException::class);

        $this->validator->validate($this->slider(['identifier' => $identifier]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidIdentifiers(): array
    {
        return [
            'empty' => [''],
            'single character' => ['a'],
            'upper case' => ['HomeNew'],
            'a space' => ['home new'],
            'a slash' => ['home/new'],
            'starts with a dash' => ['-home'],
            'too long' => [str_repeat('a', 65)],
        ];
    }

    public function testASliderAssignedToNoStoreIsRejected(): void
    {
        // Otherwise it exists, is enabled, and renders nowhere — the hardest kind of bug report.
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('at least one store view');

        $this->validator->validate($this->slider(['stores' => []]));
    }

    public function testAProductLimitOfZeroIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Number of products');

        $this->validator->validate($this->slider(['limit' => 0]));
    }

    public function testASliderThatCannotFillItsWidestRowIsRejected(): void
    {
        // Six across on a wide screen with only four products to draw: the CSS reserves six columns
        // and two of them stay empty. The block hides the controls for a slider that merely cannot
        // scroll, but it cannot invent products to close a gap.
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('more products at once than it holds');

        $this->validator->validate($this->slider(['limit' => 4, 'wide' => 6]));
    }

    public function testASliderHoldingExactlyOneRowIsAllowed(): void
    {
        // Not a carousel, but not broken either: the row is full, and `Block\Slider::isScrollable()`
        // simply renders no arrows and no dots.
        $this->validator->validate($this->slider(['limit' => 6, 'wide' => 6]));

        $this->addToAssertionCount(1);
    }

    public function testTheSlideCountIsComparedAfterClampingSoAnAbsurdValueDoesNotBlockASave(): void
    {
        // 99 normalises to the ceiling of 8; a slider holding 12 is still scrollable.
        $this->validator->validate($this->slider(['limit' => 12, 'wide' => 99]));

        $this->addToAssertionCount(1);
    }

    public function testAnAutoplayDelayIsOnlyCheckedWhenAutoplayIsOn(): void
    {
        // A leftover delay from an experiment must not block saving a slider that does not autoplay.
        $this->validator->validate($this->slider(['autoplay' => false, 'delay' => 10]));

        $this->addToAssertionCount(1);
    }

    public function testAnUnreadablyFastAutoplayIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Autoplay delay');

        $this->validator->validate($this->slider(['autoplay' => true, 'delay' => 200]));
    }

    public function testAnUnknownSourceIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unknown product source');

        $this->validator->validate($this->slider(['source' => 'telepathy']));
    }

    public function testTheSourceIsAskedLastSoThatACheapFailureNeverReachesTheDatabase(): void
    {
        $this->source->expects($this->never())->method('validateSourceValue');

        $this->expectException(LocalizedException::class);

        $this->validator->validate($this->slider(['identifier' => 'Not Valid']));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function slider(array $overrides = []): SliderInterface&MockObject
    {
        $slider = $this->createMock(SliderInterface::class);

        $slider->method('getTitle')->willReturn($overrides['title'] ?? 'New In');
        $slider->method('getIdentifier')->willReturn($overrides['identifier'] ?? 'home-new');
        $slider->method('getStoreIds')->willReturn($overrides['stores'] ?? [1]);
        $slider->method('getProductLimit')->willReturn($overrides['limit'] ?? 12);
        $slider->method('isAutoplay')->willReturn($overrides['autoplay'] ?? false);
        $slider->method('getAutoplayDelay')->willReturn($overrides['delay'] ?? 5000);
        $slider->method('getSourceType')->willReturn($overrides['source'] ?? 'new');
        $slider->method('getSourceValue')->willReturn($overrides['value'] ?? null);
        $slider->method('getSlidesPerBreakpoint')->willReturn(
            [
                Breakpoints::MOBILE => 1,
                Breakpoints::TABLET => 2,
                Breakpoints::DESKTOP => 4,
                Breakpoints::WIDE => $overrides['wide'] ?? 5,
            ]
        );

        return $slider;
    }
}
