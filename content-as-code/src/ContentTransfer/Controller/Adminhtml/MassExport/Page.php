<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Controller\Adminhtml\MassExport;

use Magento\Backend\App\Action\Context;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;
use Scr1be\ContentTransfer\Model\BundleDownload;
use Scr1be\ContentTransfer\Model\Porter\CmsPagePorter;

/**
 * "Export to bundle" on the CMS page grid.
 */
class Page extends AbstractMassExport
{
    public function __construct(
        Context $context,
        Filter $massActionFilter,
        BundleDownload $bundleDownload,
        private readonly CollectionFactory $collectionFactory,
        private readonly CmsPagePorter $porter
    ) {
        parent::__construct($context, $massActionFilter, $bundleDownload);
    }

    protected function getPorterCode(): string
    {
        return CmsPagePorter::CODE;
    }

    protected function collectKeys(Filter $filter): array
    {
        $keys = [];

        foreach ($filter->getCollection($this->collectionFactory->create()) as $page) {
            $keys[] = $this->porter->keyFor($page);
        }

        return $keys;
    }
}
