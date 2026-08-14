<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Renderer;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Pdf\InvoiceFactory as InvoicePdfFactory;
use Psr\Log\LoggerInterface;
use Scr1be\SignedDocumentDelivery\Model\Document\LoadedDocument;
use Scr1be\SignedDocumentDelivery\Model\Document\OwnershipGuard;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;

/**
 * Invoices, over Magento\Sales\Model\Order\Pdf\Invoice.
 *
 * Magento_SalesGraphQl's Invoices resolver emits `base64_encode($invoice->getEntityId())`, so the
 * UID is the primary key and `get()` is the whole lookup.
 */
class InvoiceRenderer extends AbstractPdfRenderer
{
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OwnershipGuard $guard,
        Uid $uid,
        LoggerInterface $logger,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly InvoicePdfFactory $pdfFactory
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
            $this->refuse('invoice uid "' . $entityId . '" is not an entity id');
        }

        try {
            $invoice = $this->invoiceRepository->get((int) $entityId);
        } catch (NoSuchEntityException | InputException) {
            $this->refuse('invoice ' . $entityId . ' does not exist');
        }

        // The repository's contract is InvoiceInterface; the core PDF model needs the model, and
        // calls getOrder() and getStore() on it. Asserting here means a preference that swapped the
        // implementation fails while issuing the URL rather than halfway through drawing a page.
        if (!$invoice instanceof Invoice) {
            $this->refuse('invoice ' . $entityId . ' is not a Magento\Sales\Model\Order\Invoice');
        }

        $order = $this->loadOrder((int) $invoice->getOrderId());
        $this->guard->assert($order, (int) $invoice->getStoreId(), $customerId, $storeId);

        return new LoadedDocument(
            DocumentType::INVOICE,
            $uid,
            (int) $invoice->getEntityId(),
            (string) $invoice->getIncrementId(),
            (int) $invoice->getStoreId(),
            $this->fingerprint($invoice->getUpdatedAt(), $order),
            $invoice
        );
    }

    /**
     * @inheritDoc
     */
    public function render(LoadedDocument $document): string
    {
        $invoice = $document->entity;
        if (!$invoice instanceof Invoice) {
            $this->refuse('render() was handed a ' . $invoice::class . ' instead of an invoice');
        }

        // A fresh PDF model per render, not a shared one. AbstractPdf keeps the current page
        // cursor in a public $y and the document in $_pdf; a singleton would carry the previous
        // render's cursor into the next one.
        return $this->pdfFactory->create()->getPdf([$invoice])->render();
    }
}
