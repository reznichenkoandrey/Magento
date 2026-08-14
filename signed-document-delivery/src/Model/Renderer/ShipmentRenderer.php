<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Renderer;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Pdf\ShipmentFactory as ShipmentPdfFactory;
use Magento\Sales\Model\Order\Shipment;
use Psr\Log\LoggerInterface;
use Scr1be\SignedDocumentDelivery\Model\Document\LoadedDocument;
use Scr1be\SignedDocumentDelivery\Model\Document\OwnershipGuard;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;

/**
 * Shipments, over Magento\Sales\Model\Order\Pdf\Shipment — and the one document type whose UID is
 * not what the other three led you to expect.
 *
 * Magento_SalesGraphQl encodes the primary key for orders, invoices and credit memos:
 *
 *   Model\Formatter\Order::format()      'id' => base64_encode((string)$orderModel->getEntityId())
 *   Model\Resolver\Invoices::resolve()   'id' => base64_encode($invoice->getEntityId())
 *   Model\Resolver\CreditMemos::resolve() 'id' => base64_encode($creditMemo->getEntityId())
 *
 * and then, for shipments:
 *
 *   Model\Resolver\Shipments::resolve()  'id' => base64_encode($shipment->getIncrementId())
 *
 * A client holding an `OrderShipment.id` from the customer's own order history therefore hands us
 * `MDAwMDAwMDAx` — `000000001` — and reading that as an entity id finds shipment 1, which is
 * somebody else's shipment on any installation that has shipped more than nothing. Two properties
 * of increment ids make this worse than a plain mismatch: they are zero-padded *digit strings*, so
 * `ctype_digit()` cannot tell the two apart, and `(int) '000000001'` is `1`, a perfectly valid
 * entity id. Silent wrong answer, not an error.
 *
 * So the increment-id lookup runs first and the entity-id lookup is only a fallback, for clients
 * that built their UID from a REST payload or from `Magento_SalesGraphQl`'s pre-fix behaviour.
 * Getting the order the other way round would mean a request for increment `000000001` could be
 * answered with entity 1.
 *
 * The lookup is scoped to the store because `sales_shipment` is unique on
 * (`increment_id`, `store_id`) — the SALES_SHIPMENT_INCREMENT_ID_STORE_ID constraint in
 * Magento_Sales' db_schema.xml — and not on `increment_id` alone. Two store views with independent
 * increment sequences can genuinely hold the same number.
 */
class ShipmentRenderer extends AbstractPdfRenderer
{
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OwnershipGuard $guard,
        Uid $uid,
        LoggerInterface $logger,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly ShipmentPdfFactory $pdfFactory
    ) {
        parent::__construct($orderRepository, $guard, $uid, $logger);
    }

    /**
     * @inheritDoc
     */
    public function loadAndAuthorize(string $uid, int $customerId, int $storeId): LoadedDocument
    {
        $decoded = $this->decodeUid($uid);

        $shipment = $this->findByIncrementId($decoded, $storeId) ?? $this->findByEntityId($decoded);
        if ($shipment === null) {
            $this->refuse('no shipment matches uid "' . $decoded . '" as an increment id or an entity id');
        }

        if (!$shipment instanceof Shipment) {
            $this->refuse('shipment "' . $decoded . '" is not a Magento\Sales\Model\Order\Shipment');
        }

        $order = $this->loadOrder((int) $shipment->getOrderId());
        $this->guard->assert($order, (int) $shipment->getStoreId(), $customerId, $storeId);

        return new LoadedDocument(
            DocumentType::SHIPMENT,
            $uid,
            (int) $shipment->getEntityId(),
            (string) $shipment->getIncrementId(),
            (int) $shipment->getStoreId(),
            $this->fingerprint($shipment->getUpdatedAt(), $order),
            $shipment
        );
    }

    /**
     * @inheritDoc
     */
    public function render(LoadedDocument $document): string
    {
        $shipment = $document->entity;
        if (!$shipment instanceof Shipment) {
            $this->refuse('render() was handed a ' . $shipment::class . ' instead of a shipment');
        }

        return $this->pdfFactory->create()->getPdf([$shipment])->render();
    }

    /**
     * What core's GraphQL actually emits.
     */
    private function findByIncrementId(string $incrementId, int $storeId): ?ShipmentInterface
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(ShipmentInterface::INCREMENT_ID, $incrementId)
            ->addFilter(ShipmentInterface::STORE_ID, $storeId)
            ->create();

        $matches = $this->shipmentRepository->getList($criteria)->getItems();

        return $matches === [] ? null : reset($matches);
    }

    /**
     * What a client that built its UID from REST, or from a future core fix, would send.
     */
    private function findByEntityId(string $decoded): ?ShipmentInterface
    {
        if (!ctype_digit($decoded)) {
            return null;
        }

        try {
            return $this->shipmentRepository->get((int) $decoded);
        } catch (NoSuchEntityException | InputException) {
            return null;
        }
    }
}
