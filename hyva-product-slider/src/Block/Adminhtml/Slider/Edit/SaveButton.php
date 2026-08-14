<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Block\Adminhtml\Slider\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Scr1be\HyvaProductSlider\Controller\Adminhtml\Slider\Save;

/**
 * An empty array is how a `ButtonProviderInterface` says "render nothing", which is what an admin
 * without the save privilege should see — a read-only grid entry, not a button that 403s.
 */
class SaveButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        if (!$this->isAllowed(Save::ADMIN_RESOURCE)) {
            return [];
        }

        return [
            'label' => __('Save Slider'),
            'class' => 'save primary',
            'data_attribute' => [
                'mage-init' => [
                    'buttonAdapter' => [
                        'actions' => [
                            [
                                'targetName' => 'scr1be_slider_form.scr1be_slider_form',
                                'actionName' => 'save',
                                'params' => [false],
                            ],
                        ],
                    ],
                ],
            ],
            'sort_order' => 90,
        ];
    }
}
