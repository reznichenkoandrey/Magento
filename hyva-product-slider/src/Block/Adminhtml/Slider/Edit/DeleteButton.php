<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Block\Adminhtml\Slider\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Scr1be\HyvaProductSlider\Controller\Adminhtml\Slider\Delete;

class DeleteButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        $sliderId = $this->getSliderId();

        if ($sliderId === null || !$this->isAllowed(Delete::ADMIN_RESOURCE)) {
            return [];
        }

        return [
            'label' => __('Delete Slider'),
            'class' => 'delete',
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s', {\"data\": {}})",
                __('Are you sure you want to delete this slider? Any page or widget pointing at its '
                    . 'identifier will render nothing.'),
                $this->getUrl('*/*/delete', ['slider_id' => $sliderId])
            ),
            'sort_order' => 20,
        ];
    }
}
