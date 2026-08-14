<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Controller\Adminhtml\Source;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;
use Scr1be\OrderAttribution\Api\SourceRepositoryInterface;

/**
 * Edit one source, or start a new one.
 *
 * `NewAction` forwards here rather than duplicating the page, so there is one place where the form
 * page is built and one place where the title is decided.
 */
class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Scr1be_OrderAttribution::source';

    /**
     * @param Action\Context $context
     * @param PageFactory $pageFactory
     * @param SourceRepositoryInterface $sourceRepository
     */
    public function __construct(
        Action\Context $context,
        private readonly PageFactory $pageFactory,
        private readonly SourceRepositoryInterface $sourceRepository
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $sourceId = (int)$this->getRequest()->getParam('source_id');

        if ($sourceId !== 0) {
            try {
                $this->sourceRepository->getById($sourceId);
            } catch (NoSuchEntityException) {
                $this->messageManager->addErrorMessage(__('This order source no longer exists.'));

                return $this->resultRedirectFactory->create()->setPath('*/*/index');
            }
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend(
            $sourceId === 0 ? __('New Order Source') : __('Edit Order Source')
        );

        return $page;
    }
}
