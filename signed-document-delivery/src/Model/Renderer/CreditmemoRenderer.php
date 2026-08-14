<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Model\Renderer;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Pdf\CreditmemoFactory as CreditmemoPdfFactory;
use Psr\Log\LoggerInterface;
use Scr1be\SignedDocumentDelivery\Model\Document\LoadedDocument;
use Scr1be\SignedDocumentDelivery\Model\Document\OwnershipGuard;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;

/**
 * Credit memos, over Magento\Sales\Model\Order\Pdf\Creditmemo.
 *
 * Magento_SalesGraphQl's CreditMemos resolver emits `base64_encode($creditMemo->getEntityId())`,
 * so — as with invoices — the UID is the primary key.
 */
class CreditmemoRenderer extends AbstractPdfRenderer
{
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OwnershipGuard $guard,
        Uid $uid,
        LoggerInterface $logger,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        private readonly CreditmemoPdfFactory $pdfFactory
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
            $this->refuse('credit memo uid "' . $entityId . '" is not an entity id');
        }

        try {
            $creditmemo = $this->creditmemoRepository->get((int) $entityId);
        } catch (NoSuchEntityException | InputException) {
            $this->refuse('credit memo ' . $entityId . ' does not exist');
        }

        if (!$creditmemo instanceof Creditmemo) {
            $this->refuse('credit memo ' . $entityId . ' is not a Magento\Sales\Model\Order\Creditmemo');
        }

        $order = $this->loadOrder((int) $creditmemo->getOrderId());
        $this->guard->assert($order, (int) $creditmemo->getStoreId(), $customerId, $storeId);

        return new LoadedDocument(
            DocumentType::CREDITMEMO,
            $uid,
            (int) $creditmemo->getEntityId(),
            (string) $creditmemo->getIncrementId(),
            (int) $creditmemo->getStoreId(),
            $this->fingerprint($creditmemo->getUpdatedAt(), $order),
            $creditmemo
        );
    }

    /**
     * @inheritDoc
     */
    public function render(LoadedDocument $document): string
    {
        $creditmemo = $document->entity;
        if (!$creditmemo instanceof Creditmemo) {
            $this->refuse('render() was handed a ' . $creditmemo::class . ' instead of a credit memo');
        }

        return $this->pdfFactory->create()->getPdf([$creditmemo])->render();
    }
}
