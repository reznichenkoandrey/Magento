<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Controller\Adminhtml\Slider;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;
use Scr1be\HyvaProductSlider\Api\SliderRepositoryInterface;
use Scr1be\HyvaProductSlider\Controller\Adminhtml\AbstractSlider;
use Scr1be\HyvaProductSlider\Model\ResourceModel\Slider\CollectionFactory;

/**
 * Delete everything the grid selection covers.
 *
 * The selection is resolved through `Ui\Component\MassAction\Filter::getCollection()` rather than
 * from a list of ids in the request, because "select all" in a Magento grid does not send ids at all
 * — it sends the current filters, and the filter component is what turns them back into rows. A
 * controller that reads `selected` directly silently does nothing on the one selection that matters
 * most.
 */
class MassDelete extends AbstractSlider implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Scr1be_HyvaProductSlider::slider_delete';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly SliderRepositoryInterface $sliderRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultRedirectFactory->create();

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $redirect->setPath('*/*/');
        }

        $deleted = 0;
        $failed = 0;

        foreach ($collection as $slider) {
            try {
                $this->sliderRepository->delete($slider);
                $deleted++;
            } catch (\Throwable) {
                // One undeletable row must not abandon the rest of the selection half-processed.
                $failed++;
            }
        }

        if ($deleted > 0) {
            $this->messageManager->addSuccessMessage(__('A total of %1 record(s) have been deleted.', $deleted));
        }

        if ($failed > 0) {
            $this->messageManager->addErrorMessage(__('%1 record(s) could not be deleted.', $failed));
        }

        return $redirect->setPath('*/*/');
    }
}
