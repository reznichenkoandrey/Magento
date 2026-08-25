<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Controller\Adminhtml\MassExport;

use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;
use RuntimeException;
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

    public function execute(): ResultInterface|ResponseInterface
    {
        try {
            $keys = $this->collectKeys($this->massActionFilter);
        } catch (Throwable $exception) {
            $this->messageManager->addExceptionMessage(
                $this->asException($exception),
                __('The selection could not be read.')
            );

            return $this->back();
        }

        if ($keys === []) {
            $this->messageManager->addErrorMessage(__('Nothing was selected to export.'));

            return $this->back();
        }

        try {
            return $this->bundleDownload->create(new Selection([], [$this->getPorterCode() => $keys]), false);
        } catch (Throwable $exception) {
            $this->messageManager->addExceptionMessage(
                $this->asException($exception),
                __('The bundle could not be built.')
            );

            return $this->back();
        }
    }

    /**
     * A caught `Throwable` in the shape `addExceptionMessage()` will accept.
     *
     * `Magento\Framework\Message\ManagerInterface::addExceptionMessage()` declares
     * `\Exception $exception` natively, so handing it an `\Error` raises a `TypeError` *inside*
     * the catch block — turning the failure this handler exists to absorb into a fatal, and
     * losing the message the operator was supposed to see. Core has the same problem and answers
     * it the same way in `Magento\Framework\Mview\View::update()`: wrap, keeping the original as
     * `previous` so nothing is dropped from the log.
     */
    private function asException(Throwable $caught): Exception
    {
        return $caught instanceof Exception
            ? $caught
            : new RuntimeException($caught->getMessage(), 0, $caught);
    }

    private function back(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setPath($this->massActionFilter->getComponentRefererUrl() ?: '*/*/');
    }
}
