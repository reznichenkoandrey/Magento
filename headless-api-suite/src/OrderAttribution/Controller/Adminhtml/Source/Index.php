<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Controller\Adminhtml\Source;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Source registry listing.
 */
class Index extends Action implements HttpGetActionInterface
{
    /**
     * @see \Scr1be\OrderAttribution\etc\acl.xml
     */
    public const ADMIN_RESOURCE = 'Scr1be_OrderAttribution::source';

    /**
     * @param Action\Context $context
     * @param PageFactory $pageFactory
     */
    public function __construct(
        Action\Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend(__('Order Attribution Sources'));

        return $page;
    }
}
