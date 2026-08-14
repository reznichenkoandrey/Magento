<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Test\Unit\Model\Renderer;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Pdf\InvoiceFactory as InvoicePdfFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\SignedDocumentDelivery\Exception\DocumentUnavailableException;
use Scr1be\SignedDocumentDelivery\Model\Document\OwnershipGuard;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;
use Scr1be\SignedDocumentDelivery\Model\Renderer\InvoiceRenderer;

/**
 * The straightforward renderer, kept honest on the parts that are not about UIDs: the load ladder,
 * the guard call and the one-message refusal.
 */
class InvoiceRendererTest extends TestCase
{
    private const CUSTOMER_ID = 42;
    private const STORE_ID = 1;
    private const ORDER_ID = 1000;

    /** base64('4') — what Magento_SalesGraphQl's Invoices resolver emits */
    private const UID = 'NA==';

    private OrderRepositoryInterface&MockObject $orderRepository;
    private InvoiceRepositoryInterface&MockObject $invoiceRepository;
    private OwnershipGuard&MockObject $guard;
    private LoggerInterface&MockObject $logger;
    private InvoiceRenderer $renderer;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->method('get')->willReturn($this->createMock(Order::class));

        $this->invoiceRepository = $this->createMock(InvoiceRepositoryInterface::class);
        $this->guard = $this->createMock(OwnershipGuard::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->renderer = new InvoiceRenderer(
            $this->orderRepository,
            $this->guard,
            new Uid(),
            $this->logger,
            $this->invoiceRepository,
            $this->createMock(InvoicePdfFactory::class)
        );
    }

    public function testTheUidIsAnEntityId(): void
    {
        $this->invoiceRepository->expects($this->once())
            ->method('get')
            ->with(4)
            ->willReturn($this->invoice());

        $document = $this->renderer->loadAndAuthorize(self::UID, self::CUSTOMER_ID, self::STORE_ID);

        $this->assertSame(DocumentType::INVOICE, $document->type);
        $this->assertSame(4, $document->entityId);
        $this->assertSame(self::UID, $document->uid, 'the uid is echoed back into the token payload');
        $this->assertSame('invoice-000000004.pdf', $document->filename());
    }

    public function testANonNumericUidNeverReachesTheRepository(): void
    {
        // base64('4; DROP TABLE') decodes fine and is not an id. The repository would cast it to 4
        // and hand back a real invoice.
        $this->invoiceRepository->expects($this->never())->method('get');

        $this->expectException(DocumentUnavailableException::class);

        $this->renderer->loadAndAuthorize(base64_encode('4; DROP TABLE'), self::CUSTOMER_ID, self::STORE_ID);
    }

    /**
     * @dataProvider repositoryFailures
     */
    public function testAMissingInvoiceIsRefused(\Throwable $thrown): void
    {
        $this->invoiceRepository->method('get')->willThrowException($thrown);

        $this->expectException(DocumentUnavailableException::class);
        $this->expectExceptionMessage('The requested document is not available.');

        $this->renderer->loadAndAuthorize(self::UID, self::CUSTOMER_ID, self::STORE_ID);
    }

    /**
     * @return array<string, array{0: \Throwable}>
     */
    public static function repositoryFailures(): array
    {
        return [
            'no such invoice' => [new NoSuchEntityException(new Phrase('nope'))],
            // InvoiceRepository::get() raises this for an id it will not even look up.
            'unusable id' => [new InputException(new Phrase('nope'))],
        ];
    }

    public function testAnOrphanedInvoiceIsRefusedRatherThanRenderedWithoutAnOrder(): void
    {
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willThrowException(new NoSuchEntityException(new Phrase('nope')));
        $this->invoiceRepository->method('get')->willReturn($this->invoice());

        $renderer = new InvoiceRenderer(
            $orderRepository,
            $this->guard,
            new Uid(),
            $this->logger,
            $this->invoiceRepository,
            $this->createMock(InvoicePdfFactory::class)
        );

        $this->expectException(DocumentUnavailableException::class);

        $renderer->loadAndAuthorize(self::UID, self::CUSTOMER_ID, self::STORE_ID);
    }

    public function testSomethingThatIsNotTheCoreModelIsRefusedWhileIssuingRatherThanWhileDrawing(): void
    {
        // The repository's contract is InvoiceInterface; the core PDF model calls getOrder() and
        // getStore(), which only the model has. A preference that swapped the implementation
        // should fail here, not three frames into Zend_Pdf.
        $this->invoiceRepository->method('get')->willReturn($this->createMock(InvoiceInterface::class));

        $this->expectException(DocumentUnavailableException::class);

        $this->renderer->loadAndAuthorize(self::UID, self::CUSTOMER_ID, self::STORE_ID);
    }

    public function testTheGuardDecidesAndItsRefusalIsNotSwallowed(): void
    {
        $order = $this->createMock(Order::class);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->with(self::ORDER_ID)->willReturn($order);
        $this->invoiceRepository->method('get')->willReturn($this->invoice(storeId: 5));

        $this->guard->expects($this->once())
            ->method('assert')
            ->with($order, 5, self::CUSTOMER_ID, self::STORE_ID)
            ->willThrowException(new DocumentUnavailableException(new Phrase('refused')));

        $renderer = new InvoiceRenderer(
            $orderRepository,
            $this->guard,
            new Uid(),
            $this->logger,
            $this->invoiceRepository,
            $this->createMock(InvoicePdfFactory::class)
        );

        $this->expectException(DocumentUnavailableException::class);

        $renderer->loadAndAuthorize(self::UID, self::CUSTOMER_ID, self::STORE_ID);
    }

    public function testRenderRefusesADocumentBelongingToAnotherRenderer(): void
    {
        $this->invoiceRepository->method('get')->willReturn($this->invoice());
        $document = $this->renderer->loadAndAuthorize(self::UID, self::CUSTOMER_ID, self::STORE_ID);

        $creditmemoShaped = new \Scr1be\SignedDocumentDelivery\Model\Document\LoadedDocument(
            DocumentType::CREDITMEMO,
            $document->uid,
            $document->entityId,
            $document->incrementId,
            $document->storeId,
            $document->fingerprint,
            $this->createMock(Order\Creditmemo::class)
        );

        $this->expectException(DocumentUnavailableException::class);

        $this->renderer->render($creditmemoShaped);
    }

    private function invoice(int $storeId = self::STORE_ID): Invoice&MockObject
    {
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getEntityId')->willReturn(4);
        $invoice->method('getIncrementId')->willReturn('000000004');
        $invoice->method('getStoreId')->willReturn($storeId);
        $invoice->method('getOrderId')->willReturn(self::ORDER_ID);
        $invoice->method('getUpdatedAt')->willReturn('2026-08-01 10:00:00');

        return $invoice;
    }
}
