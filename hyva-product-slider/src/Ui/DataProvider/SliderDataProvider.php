<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Ui\DataProvider;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Registry;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Controller\Adminhtml\Slider\Save;
use Scr1be\HyvaProductSlider\Model\ResourceModel\Slider\CollectionFactory;
use Scr1be\HyvaProductSlider\Model\Slider\FormDataMapper;

/**
 * What the edit form is filled with.
 *
 * Two sources, in a deliberate order. The registered slider (put there by the Edit controller) is the
 * stored state; the persisted post data, if any, is a save that was just rejected. The rejected input
 * wins, because it is what the person in front of the screen last typed — showing them the stored
 * values instead would quietly discard their work and leave an error message with nothing to fix.
 *
 * The persisted copy is cleared as it is read, so a later visit to the same form starts clean.
 */
class SliderDataProvider extends AbstractDataProvider
{
    public const REGISTRY_KEY = 'scr1be_current_slider';

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly Registry $registry,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly FormDataMapper $formDataMapper,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();

        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function getData(): array
    {
        $data = [];

        $slider = $this->registry->registry(self::REGISTRY_KEY);
        if ($slider instanceof SliderInterface) {
            $data[(int) $slider->getSliderId()] = $this->formDataMapper->toFormData($slider);
        }

        $rejected = $this->dataPersistor->get(Save::FORM_DATA_KEY);
        if (is_array($rejected) && $rejected !== []) {
            // A new slider has no id yet, and the UI form reads the first (and only) entry of the
            // array in that case, so the key is not load-bearing here.
            $data[(int) ($rejected[SliderInterface::SLIDER_ID] ?? 0)] = $rejected;
            $this->dataPersistor->clear(Save::FORM_DATA_KEY);
        }

        return $data;
    }
}
