<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Controller\Adminhtml\Slider;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;
use Scr1be\HyvaProductSlider\Controller\Adminhtml\AbstractSlider;

class Index extends AbstractSlider implements HttpGetActionInterface
{
    public function execute(): ResultInterface
    {
        /** @var Page $result */
        $result = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_PAGE);
        $result->setActiveMenu(self::ADMIN_RESOURCE);
        $result->getConfig()->getTitle()->prepend(__('Product Sliders'));

        return $result;
    }
}
