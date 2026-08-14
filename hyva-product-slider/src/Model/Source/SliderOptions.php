<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Model\ResourceModel\Slider\CollectionFactory;

/**
 * The widget's slider picker.
 *
 * Disabled sliders are listed too, marked as such, because a merchandiser routinely builds a slider
 * and the page that will hold it in either order — hiding the half-finished one would mean saving the
 * widget twice. What they must not be able to do is pick a slider that no longer exists, which is why
 * this reads the table rather than a config list.
 */
class SliderOptions implements OptionSourceInterface
{
    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setOrder(SliderInterface::TITLE, 'ASC');

        $options = [];

        /** @var SliderInterface $slider */
        foreach ($collection as $slider) {
            $label = sprintf('%s (%s)', $slider->getTitle(), $slider->getIdentifier());

            $options[] = [
                'value' => $slider->getIdentifier(),
                'label' => $slider->isActive() ? $label : (string) __('%1 — disabled', $label),
            ];
        }

        return $options;
    }
}
