<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Controller\Adminhtml\Slider;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page as BackendPage;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\Page;
use Scr1be\HyvaProductSlider\Api\SliderRepositoryInterface;
use Scr1be\HyvaProductSlider\Controller\Adminhtml\AbstractSlider;

/**
 * The edit form.
 *
 * The loaded slider goes into the registry under `SliderDataProvider::REGISTRY_KEY` so the form's
 * data provider and its buttons read the same instance the controller already fetched. The registry
 * is the seam Magento's UI form gives you for that; the alternative is three components loading the
 * same row from three places.
 */
class Edit extends AbstractSlider implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly SliderRepositoryInterface $sliderRepository,
        private readonly Registry $registry
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $sliderId = (int) $this->getRequest()->getParam('slider_id');

        try {
            $slider = $this->sliderRepository->getById($sliderId);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This slider no longer exists.'));

            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }

        $this->registry->register(\Scr1be\HyvaProductSlider\Ui\DataProvider\SliderDataProvider::REGISTRY_KEY, $slider);

        /** @var BackendPage $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $result->setActiveMenu(self::ADMIN_RESOURCE);
        $result->getConfig()->getTitle()->prepend(__('Edit Slider "%1"', $slider->getTitle()));

        return $result;
    }
}
