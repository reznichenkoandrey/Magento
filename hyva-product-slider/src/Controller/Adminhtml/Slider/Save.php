<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Controller\Adminhtml\Slider;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Api\SliderRepositoryInterface;
use Scr1be\HyvaProductSlider\Controller\Adminhtml\AbstractSlider;
use Scr1be\HyvaProductSlider\Model\Slider\FormDataMapper;
use Scr1be\HyvaProductSlider\Model\SliderFactory;

/**
 * Create or update.
 *
 * On a rejected save the submitted values are put back into the session under the key the form's data
 * provider reads, so the merchandiser lands on their own input with the error over it rather than on
 * an empty form. That round trip is the difference between a validation message and losing ten
 * minutes of work.
 */
class Save extends AbstractSlider implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Scr1be_HyvaProductSlider::slider_save';

    public const FORM_DATA_KEY = 'scr1be_slider_form_data';

    public function __construct(
        Context $context,
        private readonly SliderRepositoryInterface $sliderRepository,
        private readonly SliderFactory $sliderFactory,
        private readonly FormDataMapper $formDataMapper,
        private readonly DataPersistorInterface $dataPersistor
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultRedirectFactory->create();

        $formData = $this->getRequest()->getPostValue();
        if (!$formData) {
            return $redirect->setPath('*/*/');
        }

        $sliderId = (int) ($formData[SliderInterface::SLIDER_ID] ?? 0);

        try {
            $slider = $sliderId > 0 ? $this->sliderRepository->getById($sliderId) : $this->sliderFactory->create();
            $this->formDataMapper->applyToSlider($formData, $slider);

            $slider = $this->sliderRepository->save($slider);
            $this->messageManager->addSuccessMessage(__('The slider has been saved.'));
            $this->dataPersistor->clear(self::FORM_DATA_KEY);

            if ($this->getRequest()->getParam('back') === 'edit') {
                return $redirect->setPath('*/*/edit', ['slider_id' => $slider->getSliderId()]);
            }

            return $redirect->setPath('*/*/');
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This slider no longer exists.'));

            return $redirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            // addExceptionMessage() declares `\Exception` natively, so an `\Error` handed to it
            // raises a TypeError inside this block — the broad catch would produce the fatal it
            // exists to prevent, and the operator would lose both the message and the form data
            // persisted below. Core wraps for the same reason in `Mview\View::update()`.
            $this->messageManager->addExceptionMessage(
                $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e),
                __('The slider could not be saved.')
            );
        }

        $this->dataPersistor->set(self::FORM_DATA_KEY, $formData);

        return $sliderId > 0
            ? $redirect->setPath('*/*/edit', ['slider_id' => $sliderId])
            : $redirect->setPath('*/*/new');
    }
}
