<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Controller\Adminhtml\Source;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Scr1be\OrderAttribution\Api\SourceRepositoryInterface;

/**
 * Remove one source from the registry.
 *
 * POST only. A delete behind a GET is a delete a crawler, a prefetching browser or a pasted link can
 * perform, and the admin form key does not help when the request is a link the admin themselves
 * clicked.
 *
 * Deleting is safe for history: the order columns are soft references with no foreign key, so orders
 * placed through a deleted source keep saying so. The grid then shows a code with no label, which is
 * why the admin UI recommends deactivating instead.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Scr1be_OrderAttribution::source';

    /**
     * @param Action\Context $context
     * @param SourceRepositoryInterface $sourceRepository
     */
    public function __construct(
        Action\Context $context,
        private readonly SourceRepositoryInterface $sourceRepository
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $sourceId = (int)$this->getRequest()->getParam('source_id');

        if ($sourceId === 0) {
            $this->messageManager->addErrorMessage(__('No order source was specified.'));

            return $redirect->setPath('*/*/index');
        }

        try {
            $this->sourceRepository->delete($this->sourceRepository->getById($sourceId));
            $this->messageManager->addSuccessMessage(__('The order source has been deleted.'));
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This order source no longer exists.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $redirect->setPath('*/*/index');
    }
}
