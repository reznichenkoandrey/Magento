<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Controller\Adminhtml\Slider;

use Magento\Backend\Model\View\Result\Page as BackendPage;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;
use Scr1be\HyvaProductSlider\Controller\Adminhtml\AbstractSlider;

class NewAction extends AbstractSlider implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Scr1be_HyvaProductSlider::slider_save';

    public function execute(): ResultInterface
    {
        /** @var BackendPage $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $result->setActiveMenu(AbstractSlider::ADMIN_RESOURCE);
        $result->getConfig()->getTitle()->prepend(__('New Slider'));

        return $result;
    }
}
