<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Controller\Adminhtml\MassExport;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Ui\Component\MassAction\Filter;
use Scr1be\ContentTransfer\Model\BundleDownload;
use Scr1be\ContentTransfer\Model\Selection;
use Throwable;

/**
 * Shared half of the "Export to bundle" mass actions on the native CMS grids.
 *
 * A mass action that answers with a file works because of how the grid submits: `mageUtils.submit()`
 * (`lib/web/mage/utils/misc.js`) builds a real `<form>`, stamps `window.FORM_KEY` into it, appends it
 * to the document and calls `form.submit()`. That is an ordinary top-level POST, so a response
 * carrying `Content-Disposition: attachment` downloads the bundle and leaves the grid exactly where
 * it was — no redirect, no lost selection, no page reload.
 */
abstract class AbstractMassExport extends Action implements HttpPostActionInterface
{
    /**
     * @see _isAllowed()
     */
    public const ADMIN_RESOURCE = 'Scr1be_ContentTransfer::export';

    public function __construct(
        Context $context,
        private readonly Filter $massActionFilter,
        private readonly BundleDownload $bundleDownload
    ) {
        parent::__construct($context);
    }

    /**
     * The porter this grid's rows belong to.
     */
    abstract protected function getPorterCode(): string;

    /**
     * Bundle keys for the rows the operator selected, resolved through
     * `Magento\Ui\Component\MassAction\Filter`, which is what makes "select all 4,000" work without
     * the ids ever travelling in the request.
     *
     * @return string[]
     */
    abstract protected function collectKeys(Filter $filter): array;

    public function execute(): ResponseInterface|Redirect
    {
        try {
            $keys = $this->collectKeys($this->massActionFilter);
        } catch (Throwable $exception) {
            $this->messageManager->addExceptionMessage($exception, __('The selection could not be read.'));

            return $this->back();
        }

        if ($keys === []) {
            $this->messageManager->addErrorMessage(__('Nothing was selected to export.'));

            return $this->back();
        }

        try {
            return $this->bundleDownload->create(new Selection([], [$this->getPorterCode() => $keys]), false);
        } catch (Throwable $exception) {
            $this->messageManager->addExceptionMessage($exception, __('The bundle could not be built.'));

            return $this->back();
        }
    }

    private function back(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setPath($this->massActionFilter->getComponentRefererUrl() ?: '*/*/');
    }
}
