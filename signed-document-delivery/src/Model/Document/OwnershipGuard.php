<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Document;

use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Scr1be\SignedDocumentDelivery\Exception\DocumentUnavailableException;

/**
 * The authorization half of every renderer's `loadAndAuthorize()`.
 *
 * Two independent facts have to hold before a PDF is produced:
 *
 *  - the order behind the document belongs to this customer, and
 *  - the document belongs to the store view the request was made in.
 *
 * The store check is not redundant with the customer check. A customer account is shared across
 * every store view of a website, so ownership alone would let a request made against store view A
 * pull a document rendered for store view B — different logo, different address block, different
 * locale, and on a multi-brand installation a different brand entirely. Core's own PDF models
 * emulate the document's store while drawing (Magento\Sales\Model\Order\Pdf\Invoice::getPdf()
 * starts environment emulation from `$invoice->getStoreId()`), so without this check the delivered
 * file would be correct and still be the wrong store's paperwork.
 *
 * Guest orders are refused outright: `customer_id` is null on them, and a null-vs-int comparison
 * that happens to be false is a security control that works by accident. Guest document delivery
 * needs an order-token flow of its own, which this module does not attempt.
 */
class OwnershipGuard
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws DocumentUnavailableException Always with the same message, whatever actually failed
     */
    public function assert(
        OrderInterface $order,
        int $documentStoreId,
        int $customerId,
        int $storeId
    ): void {
        $orderId = (int) $order->getEntityId();
        $orderCustomerId = $order->getCustomerId();

        if ($orderCustomerId === null) {
            $this->refuse(sprintf('order %d is a guest order and has no owner to compare against', $orderId));
        }

        if ((int) $orderCustomerId !== $customerId) {
            $this->refuse(sprintf('order %d belongs to customer %d', $orderId, (int) $orderCustomerId));
        }

        if ($documentStoreId !== $storeId) {
            $this->refuse(
                sprintf('document of order %d is scoped to store %d, asked for in store %d', $orderId, $documentStoreId, $storeId)
            );
        }
    }

    /**
     * @throws DocumentUnavailableException
     */
    private function refuse(string $reason): never
    {
        // The operator gets the reason; the client gets one message for every possible failure.
        $this->logger->warning('Scr1be_SignedDocumentDelivery refused a document: ' . $reason);

        throw new DocumentUnavailableException(__('The requested document is not available.'));
    }
}
