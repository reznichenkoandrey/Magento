<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Controller\Adminhtml\Source;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Scr1be\OrderAttribution\Api\Data\SourceInterface;
use Scr1be\OrderAttribution\Api\SourceRepositoryInterface;
use Scr1be\OrderAttribution\Model\SourceFactory;
use Scr1be\OrderAttribution\Ui\DataProvider\SourceFormDataProvider;

/**
 * Persist one source.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Scr1be_OrderAttribution::source';

    /**
     * A code travels in a URL, in a JSON body and into a varchar(32) column, and it is grouped on in
     * every report. Restricting it to lowercase letters, digits, hyphen and underscore removes the
     * whole class of "why does the report have `iOS ` and `ios`" questions before it starts.
     */
    private const CODE_PATTERN = '/^[a-z0-9][a-z0-9_-]{1,31}$/';

    /**
     * @param Action\Context $context
     * @param SourceRepositoryInterface $sourceRepository
     * @param SourceFactory $sourceFactory
     */
    public function __construct(
        Action\Context $context,
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly SourceFactory $sourceFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();

        if (!$data) {
            return $redirect->setPath('*/*/index');
        }

        $sourceId = (int)($data['source_id'] ?? 0);

        try {
            $source = $sourceId !== 0 ? $this->sourceRepository->getById($sourceId) : $this->sourceFactory->create();

            $code = strtolower(trim((string)($data[SourceInterface::CODE] ?? '')));
            if (!preg_match(self::CODE_PATTERN, $code)) {
                throw new LocalizedException(
                    __('The code must be 2–32 characters of lowercase letters, digits, "-" or "_".')
                );
            }

            $label = trim((string)($data[SourceInterface::LABEL] ?? ''));
            if ($label === '') {
                throw new LocalizedException(__('A label is required.'));
            }

            $source->setCode($code);
            $source->setLabel($label);
            $source->setIsActive((bool)(int)($data[SourceInterface::IS_ACTIVE] ?? 0));
            $source->setSortOrder((int)($data[SourceInterface::SORT_ORDER] ?? 0));

            $this->sourceRepository->save($source);
            $this->messageManager->addSuccessMessage(__('The order source has been saved.'));

            if ($this->getRequest()->getParam('back') !== null) {
                return $redirect->setPath('*/*/edit', ['source_id' => $source->getSourceId()]);
            }

            return $redirect->setPath('*/*/index');
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('This order source no longer exists.'));

            return $redirect->setPath('*/*/index');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->_getSession()->setData(SourceFormDataProvider::FORM_DATA_KEY, $data);

            return $redirect->setPath('*/*/edit', ['source_id' => $sourceId ?: null]);
        }
    }
}
