<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model;

use Magento\Framework\Exception\LocalizedException;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Model\ProductSource\Pool;

/**
 * Everything a slider has to be true about before it is allowed into the database.
 *
 * It lives in front of the repository rather than inside the admin controller because the controller
 * is one of three writers — a data patch, a fixture and an integration test are the others — and a
 * rule enforced only in the controller is a rule the other two do not have.
 *
 * The ladder is ordered by how expensive each check is, cheapest first: an identifier with a space in
 * it is rejected before anything asks the database whether its SKU list exists.
 */
class SliderValidator
{
    /**
     * Lower-case, digits, dash and underscore. The identifier ends up in layout XML, in a widget
     * parameter and in a DOM id, and each of those has its own opinion about the rest of ASCII.
     */
    private const IDENTIFIER_PATTERN = '/^[a-z0-9][a-z0-9_-]{1,63}$/';

    private const MIN_PRODUCT_LIMIT = 1;
    private const MAX_PRODUCT_LIMIT = 60;

    /**
     * Below a second an autoplaying carousel is unreadable; above a minute it is not autoplaying in
     * any sense the visitor will notice.
     */
    private const MIN_AUTOPLAY_DELAY_MS = 1000;
    private const MAX_AUTOPLAY_DELAY_MS = 60000;

    public function __construct(
        private readonly Pool $sourcePool,
        private readonly Breakpoints $breakpoints
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function validate(SliderInterface $slider): void
    {
        if (trim($slider->getTitle()) === '') {
            throw new LocalizedException(__('Enter a title for the slider.'));
        }

        if (preg_match(self::IDENTIFIER_PATTERN, $slider->getIdentifier()) !== 1) {
            throw new LocalizedException(
                __(
                    'The identifier "%1" is not valid. Use 2 to 64 lower-case letters, digits, '
                    . 'dashes or underscores, starting with a letter or digit.',
                    $slider->getIdentifier()
                )
            );
        }

        if ($slider->getStoreIds() === []) {
            throw new LocalizedException(__('Assign the slider to at least one store view.'));
        }

        $limit = $slider->getProductLimit();
        if ($limit < self::MIN_PRODUCT_LIMIT || $limit > self::MAX_PRODUCT_LIMIT) {
            throw new LocalizedException(
                __(
                    'Number of products must be between %1 and %2.',
                    self::MIN_PRODUCT_LIMIT,
                    self::MAX_PRODUCT_LIMIT
                )
            );
        }

        // A carousel that shows every slide it holds has nothing to scroll to, so the widest
        // breakpoint is what decides whether the configuration means anything.
        if ($limit < $this->breakpoints->getWidest($slider->getSlidesPerBreakpoint())) {
            throw new LocalizedException(
                __('The slider shows more products at once than it holds. Raise the number of products.')
            );
        }

        if ($slider->isAutoplay()) {
            $delay = $slider->getAutoplayDelay();
            if ($delay < self::MIN_AUTOPLAY_DELAY_MS || $delay > self::MAX_AUTOPLAY_DELAY_MS) {
                throw new LocalizedException(
                    __(
                        'Autoplay delay must be between %1 and %2 milliseconds.',
                        self::MIN_AUTOPLAY_DELAY_MS,
                        self::MAX_AUTOPLAY_DELAY_MS
                    )
                );
            }
        }

        // Last, because this is the only check that can reach the database.
        $this->sourcePool->get($slider->getSourceType())->validateSourceValue($slider->getSourceValue());
    }
}
