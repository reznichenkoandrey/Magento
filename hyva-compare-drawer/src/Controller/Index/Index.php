<?php
declare(strict_types=1);

namespace Scr1be\HyvaCompareDrawer\Controller\Index;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the client-side compare page. There is intentionally no PHP-side data fetch —
 * the page reads the same Alpine $store.compare the drawer uses, so the storefront and the
 * dedicated comparison page share a single source of truth (localStorage, namespaced
 * `scr1be_compare_v1`).
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
    ) {
    }

    public function execute(): ResultInterface
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string) __('Compare products'));
        return $page;
    }
}
