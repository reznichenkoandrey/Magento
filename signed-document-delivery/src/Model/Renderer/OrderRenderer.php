<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Renderer;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Scr1be\SignedDocumentDelivery\Model\Document\LoadedDocument;
use Scr1be\SignedDocumentDelivery\Model\Document\OwnershipGuard;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;
use Scr1be\SignedDocumentDelivery\Model\Pdf\OrderFactory as OrderPdfFactory;

/**
 * Orders, over this module's own Model\Pdf\Order.
 *
 * Magento_SalesGraphQl's Model\Formatter\Order::format() emits
 * `base64_encode((string)$orderModel->getEntityId())`, so — like invoices and credit memos — the UID
 * is the primary key.
 *
 * The order is its own parent, so the ownership guard is handed the same object twice. That reads
 * oddly and is correct: the guard's contract is "this order, and this document's store", and for an
 * order document those are the same row.
 */
class OrderRenderer extends AbstractPdfRenderer
{
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OwnershipGuard $guard,
        Uid $uid,
        LoggerInterface $logger,
        private readonly OrderPdfFactory $pdfFactory
    ) {
        parent::__construct($orderRepository, $guard, $uid, $logger);
    }

    /**
     * @inheritDoc
     */
    public function loadAndAuthorize(string $uid, int $customerId, int $storeId): LoadedDocument
    {
        $entityId = $this->decodeUid($uid);
        if (!ctype_digit($entityId)) {
            $this->refuse('order uid "' . $entityId . '" is not an entity id');
        }

        try {
            $order = $this->orderRepository->get((int) $entityId);
        } catch (NoSuchEntityException | InputException) {
            $this->refuse('order ' . $entityId . ' does not exist');
        }

        if (!$order instanceof Order) {
            $this->refuse('order ' . $entityId . ' is not a Magento\Sales\Model\Order');
        }

        $this->guard->assert($order, (int) $order->getStoreId(), $customerId, $storeId);

        return new LoadedDocument(
            DocumentType::ORDER,
            $uid,
            (int) $order->getEntityId(),
            (string) $order->getIncrementId(),
            (int) $order->getStoreId(),
            // Both halves of the fingerprint collapse to the same timestamp here, because for an
            // order document the document and its parent order are one row.
            $this->fingerprint($order->getUpdatedAt(), $order),
            $order
        );
    }

    /**
     * @inheritDoc
     */
    public function render(LoadedDocument $document): string
    {
        $order = $document->entity;
        if (!$order instanceof Order) {
            $this->refuse('render() was handed a ' . $order::class . ' instead of an order');
        }

        return $this->pdfFactory->create()->getPdf([$order])->render();
    }
}
