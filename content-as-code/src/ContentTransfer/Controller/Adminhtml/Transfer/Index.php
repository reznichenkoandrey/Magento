<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Controller\Adminhtml\Transfer;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;

/**
 * Content > Content Transfer.
 */
class Index extends Action implements HttpGetActionInterface
{
    /**
     * @see _isAllowed()
     */
    public const ADMIN_RESOURCE = 'Scr1be_ContentTransfer::transfer';

    public function execute(): Page
    {
        /** @var Page $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $result->setActiveMenu(self::ADMIN_RESOURCE);
        $result->getConfig()->getTitle()->prepend(__('Content Transfer'));

        return $result;
    }
}
