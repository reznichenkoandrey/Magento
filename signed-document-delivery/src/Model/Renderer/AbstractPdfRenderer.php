<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Renderer;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Scr1be\SignedDocumentDelivery\Exception\DocumentUnavailableException;
use Scr1be\SignedDocumentDelivery\Model\Document\OwnershipGuard;

/**
 * The half of a renderer that is the same for all four document types.
 *
 * UID decoding, loading the parent order, building the fingerprint and refusing with one message —
 * none of that varies. What varies is the repository, the core PDF model and, for shipments, what
 * the UID actually encodes. Those are the abstract methods.
 */
abstract class AbstractPdfRenderer implements DocumentRendererInterface
{
    public function __construct(
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly OwnershipGuard $guard,
        protected readonly Uid $uid,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * Decode a client-supplied UID, refusing anything that is not base64.
     *
     * Magento\Framework\GraphQl\Query\Uid::isValidBase64() is stricter than base64_decode($s, true)
     * on its own: it re-encodes and compares, so padding-mangled input is rejected rather than
     * silently decoded to something else. Checking first means decode() cannot reach its
     * GraphQlInputException branch, which matters because this code also runs in a frontend
     * controller where a GraphQL exception would be the wrong shape entirely.
     *
     * @throws DocumentUnavailableException
     */
    protected function decodeUid(string $uid): string
    {
        if ($uid === '' || !$this->uid->isValidBase64($uid)) {
            $this->refuse('uid is not valid base64');
        }

        $decoded = $this->uid->decode($uid);
        if ($decoded === null || $decoded === '') {
            $this->refuse('uid decoded to nothing');
        }

        return $decoded;
    }

    /**
     * @throws DocumentUnavailableException
     */
    protected function loadOrder(int $orderId): OrderInterface
    {
        try {
            return $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException | InputException) {
            // A document row whose order_id points at nothing is a broken installation, not an
            // attack — but the customer still gets the one message, and the operator gets the id.
            $this->refuse('order ' . $orderId . ' behind the document could not be loaded');
        }
    }

    /**
     * A value that changes whenever the rendered bytes would change.
     *
     * Both timestamps are needed. The document's own `updated_at` misses everything the PDF borrows
     * from the order — the billing and shipping addresses, the payment block and the shipping
     * description are all drawn from the order by
     * Magento\Sales\Model\Order\Pdf\AbstractPdf::insertOrder(), so an address correction saved
     * against the order alone would otherwise keep serving the stale invoice.
     */
    protected function fingerprint(?string $documentUpdatedAt, OrderInterface $order): string
    {
        return ($documentUpdatedAt ?? '') . '|' . ($order->getUpdatedAt() ?? '');
    }

    /**
     * @throws DocumentUnavailableException
     */
    protected function refuse(string $reason): never
    {
        $this->logger->warning('Scr1be_SignedDocumentDelivery refused a document: ' . $reason);

        throw new DocumentUnavailableException(__('The requested document is not available.'));
    }
}
