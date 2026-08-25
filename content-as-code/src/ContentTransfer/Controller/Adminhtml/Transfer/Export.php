<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Controller\Adminhtml\Transfer;

use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use RuntimeException;
use Scr1be\ContentTransfer\Block\Adminhtml\Transfer\Picker;
use Scr1be\ContentTransfer\Model\BundleDownload;
use Scr1be\ContentTransfer\Model\PorterPool;
use Scr1be\ContentTransfer\Model\Selection;
use Throwable;

/**
 * Turns the ticked boxes into one downloaded bundle.
 *
 * POST-only, so it is covered by Magento's form-key check: an export is a read, but it is a read of
 * every piece of content in the store, and a `GET` would make it reachable from an `<img>` tag on
 * another site.
 */
class Export extends Action implements HttpPostActionInterface
{
    /**
     * @see _isAllowed()
     */
    public const ADMIN_RESOURCE = 'Scr1be_ContentTransfer::export';

    public function __construct(
        Context $context,
        private readonly BundleDownload $bundleDownload,
        private readonly PorterPool $porterPool
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface|ResponseInterface
    {
        $identifiers = $this->selectedIdentifiers();

        if ($identifiers === []) {
            $this->messageManager->addErrorMessage(__('Select at least one entry to export.'));

            return $this->backToPicker();
        }

        try {
            return $this->bundleDownload->create(
                new Selection($this->selectedStoreIds(), $identifiers),
                $this->getRequest()->getParam(Picker::PARAM_FORMAT) === Picker::FORMAT_ZIP
            );
        } catch (Throwable $exception) {
            // A failed export must land the operator back on the page with a message rather than on
            // a stack trace: the input came from a form, so a bad selection is a normal outcome.
            //
            // Which is why the catch is this wide, and why the value has to be narrowed again
            // before it is handed on: addExceptionMessage() declares `\Exception` natively, so an
            // `\Error` would raise a TypeError inside this block and produce exactly the stack
            // trace the catch was written to prevent. Core wraps for the same reason in
            // `Magento\Framework\Mview\View::update()`; `previous` keeps the original for the log.
            $this->messageManager->addExceptionMessage(
                $exception instanceof Exception
                    ? $exception
                    : new RuntimeException($exception->getMessage(), 0, $exception),
                __('The bundle could not be built.')
            );

            return $this->backToPicker();
        }
    }

    /**
     * @return array<string, string[]>
     */
    private function selectedIdentifiers(): array
    {
        $selected = [];

        foreach ((array)$this->getRequest()->getParam(Picker::PARAM_ENTRIES, []) as $porterCode => $keys) {
            $porterCode = (string)$porterCode;

            // The form is the only caller, but its field names arrive as user input like any other:
            // an unknown porter code is dropped rather than passed on to the pool to throw over.
            if (!$this->porterPool->has($porterCode) || !is_array($keys) || $keys === []) {
                continue;
            }

            $selected[$porterCode] = array_values(array_map('strval', $keys));
        }

        return $selected;
    }

    /**
     * @return int[]
     */
    private function selectedStoreIds(): array
    {
        $storeIds = [];

        foreach ((array)$this->getRequest()->getParam(Picker::PARAM_STORE, []) as $storeId) {
            $storeIds[] = (int)$storeId;
        }

        return $storeIds;
    }

    private function backToPicker(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setPath('*/*/index');
    }
}
