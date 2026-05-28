<?php
declare(strict_types=1);

namespace Scr1be\HyvaQuickView\Controller\Product;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\LayoutFactory;
use Scr1be\HyvaQuickView\ViewModel\QuickView;

class Info implements HttpGetActionInterface
{
    private const BLOCK_TEMPLATE = 'Scr1be_HyvaQuickView::modal/quick-view-body.phtml';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly LayoutFactory $layoutFactory,
        private readonly QuickView $quickView,
    ) {
    }

    public function execute(): ResultInterface
    {
        $productId = (int) $this->request->getParam('id');
        $result = $this->jsonFactory->create();

        try {
            $product = $this->quickView->getProduct($productId);
        } catch (NoSuchEntityException) {
            return $result->setHttpResponseCode(404)
                ->setData(['error' => 'Product not found']);
        }

        // Block is created at runtime (not declared in layout XML), so the
        // ViewModel is wired here. Declarative blocks in this module receive
        // ViewModels through <arguments> in default.xml.
        $block = $this->layoutFactory->create()
            ->createBlock(\Magento\Framework\View\Element\Template::class)
            ->setTemplate(self::BLOCK_TEMPLATE)
            ->setData('product', $product)
            ->setData('view_model', $this->quickView);

        return $result->setData([
            'title' => $product->getName(),
            'html'  => $block->toHtml(),
        ]);
    }
}
