<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Controller\Adminhtml\Slider;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Scr1be\HyvaProductSlider\Api\SliderRepositoryInterface;
use Scr1be\HyvaProductSlider\Controller\Adminhtml\AbstractSlider;

/**
 * POST-only, because deleting on a GET is one crawler away from an empty grid.
 */
class Delete extends AbstractSlider implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Scr1be_HyvaProductSlider::slider_delete';

    public function __construct(
        Context $context,
        private readonly SliderRepositoryInterface $sliderRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultRedirectFactory->create();
        $sliderId = (int) $this->getRequest()->getParam('slider_id');

        if ($sliderId <= 0) {
            $this->messageManager->addErrorMessage(__('This slider no longer exists.'));

            return $redirect->setPath('*/*/');
        }

        try {
            $this->sliderRepository->deleteById($sliderId);
            $this->messageManager->addSuccessMessage(__('The slider has been deleted.'));
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This slider no longer exists.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $redirect->setPath('*/*/');
    }
}
