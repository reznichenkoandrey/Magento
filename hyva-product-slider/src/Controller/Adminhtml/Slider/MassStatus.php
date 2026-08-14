<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Controller\Adminhtml\Slider;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Api\SliderRepositoryInterface;
use Scr1be\HyvaProductSlider\Controller\Adminhtml\AbstractSlider;
use Scr1be\HyvaProductSlider\Model\ResourceModel\Slider\CollectionFactory;

/**
 * Enable or disable a selection, target state from the `status` request parameter.
 *
 * One controller rather than two because the two differ by a boolean and nothing else — and the
 * status is read as a strict `"1"` comparison so that a missing or malformed parameter disables
 * rather than enables. Publishing something because a URL was truncated is the worse failure.
 *
 * The saves go through the repository, so the validator runs on each row: a slider that was stored
 * before its source module was disabled cannot be re-enabled into a broken state.
 */
class MassStatus extends AbstractSlider implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Scr1be_HyvaProductSlider::slider_save';

    private const PARAM_STATUS = 'status';

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
        $isActive = $this->getRequest()->getParam(self::PARAM_STATUS) === '1';

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $redirect->setPath('*/*/');
        }

        $updated = 0;
        $failures = [];

        /** @var SliderInterface $slider */
        foreach ($collection as $slider) {
            if ($slider->isActive() === $isActive) {
                continue;
            }

            try {
                $this->sliderRepository->save($slider->setIsActive($isActive));
                $updated++;
            } catch (LocalizedException $e) {
                $failures[] = sprintf('%s: %s', $slider->getIdentifier(), $e->getMessage());
            }
        }

        if ($updated > 0) {
            $this->messageManager->addSuccessMessage(__('A total of %1 record(s) have been updated.', $updated));
        }

        foreach ($failures as $failure) {
            $this->messageManager->addErrorMessage(__($failure));
        }

        return $redirect->setPath('*/*/');
    }
}
