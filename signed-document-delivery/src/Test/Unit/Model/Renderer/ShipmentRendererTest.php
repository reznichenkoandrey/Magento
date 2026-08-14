<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Test\Unit\Model\Renderer;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\Data\ShipmentSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Pdf\ShipmentFactory as ShipmentPdfFactory;
use Magento\Sales\Model\Order\Shipment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\SignedDocumentDelivery\Exception\DocumentUnavailableException;
use Scr1be\SignedDocumentDelivery\Model\Document\OwnershipGuard;
use Scr1be\SignedDocumentDelivery\Model\DocumentType;
use Scr1be\SignedDocumentDelivery\Model\Renderer\ShipmentRenderer;

/**
 * The interesting renderer: the one whose UID is not what the other three led you to expect.
 *
 * Magento_SalesGraphQl's Shipments resolver emits base64(increment_id) while Orders, Invoices and
 * CreditMemos all emit base64(entity_id). Increment ids are zero-padded digit strings, so
 * `ctype_digit()` cannot tell the two apart and `(int) '000000001'` is a perfectly plausible entity
 * id — which makes getting the lookup order wrong a silent wrong answer rather than an error.
 */
class ShipmentRendererTest extends TestCase
{
    private const CUSTOMER_ID = 42;
    private const STORE_ID = 1;
    private const ORDER_ID = 1000;

    /** base64('000000001') — exactly what core's Shipments resolver puts in OrderShipment.id */
    private const UID_INCREMENT = 'MDAwMDAwMDAx';

    /** base64('7') — what a client that built its UID from a REST payload would send */
    private const UID_ENTITY = 'Nw==';

    private OrderRepositoryInterface&MockObject $orderRepository;
    private OwnershipGuard&MockObject $guard;
    private LoggerInterface&MockObject $logger;
    private ShipmentRepositoryInterface&MockObject $shipmentRepository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private ShipmentRenderer $renderer;

    /** @var array<int, array{0: string, 1: mixed}> */
    private array $filters = [];

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->method('get')->willReturn($this->createMock(Order::class));

        $this->guard = $this->createMock(OwnershipGuard::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->shipmentRepository = $this->createMock(ShipmentRepositoryInterface::class);

        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->searchCriteriaBuilder->method('addFilter')
            ->willReturnCallback(function (string $field, $value): SearchCriteriaBuilder {
                $this->filters[] = [$field, $value];

                return $this->searchCriteriaBuilder;
            });
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $this->renderer = new ShipmentRenderer(
            $this->orderRepository,
            $this->guard,
            new Uid(),
            $this->logger,
            $this->shipmentRepository,
            $this->searchCriteriaBuilder,
            $this->createMock(ShipmentPdfFactory::class)
        );
    }

    public function testAUidFromCoresGraphQlIsResolvedAsAnIncrementId(): void
    {
        $this->searchResult([$this->shipment(entityId: 4, incrementId: '000000001')]);
        // The trap: shipment 1 exists, belongs to somebody else, and is what an entity-id-first
        // lookup would have returned for this UID.
        $this->shipmentRepository->expects($this->never())->method('get');

        $document = $this->renderer->loadAndAuthorize(self::UID_INCREMENT, self::CUSTOMER_ID, self::STORE_ID);

        $this->assertSame(4, $document->entityId);
        $this->assertSame('000000001', $document->incrementId);
        $this->assertSame(DocumentType::SHIPMENT, $document->type);
    }

    public function testTheIncrementLookupIsScopedToTheStore(): void
    {
        // sales_shipment is unique on (increment_id, store_id), not on increment_id — two store
        // views with independent sequences can hold the same number.
        $this->searchResult([$this->shipment()]);

        $this->renderer->loadAndAuthorize(self::UID_INCREMENT, self::CUSTOMER_ID, self::STORE_ID);

        $this->assertSame(
            [[ShipmentInterface::INCREMENT_ID, '000000001'], [ShipmentInterface::STORE_ID, self::STORE_ID]],
            $this->filters
        );
    }

    public function testAnEntityIdUidFallsThroughToTheRepository(): void
    {
        $this->searchResult([]);
        $this->shipmentRepository->expects($this->once())
            ->method('get')
            ->with(7)
            ->willReturn($this->shipment(entityId: 7, incrementId: '000000009'));

        $document = $this->renderer->loadAndAuthorize(self::UID_ENTITY, self::CUSTOMER_ID, self::STORE_ID);

        $this->assertSame(7, $document->entityId);
    }

    public function testANonNumericUidNeverReachesTheEntityIdLookup(): void
    {
        $this->searchResult([]);
        $this->shipmentRepository->expects($this->never())->method('get');

        $this->expectException(DocumentUnavailableException::class);

        // base64('not-a-number')
        $this->renderer->loadAndAuthorize('bm90LWEtbnVtYmVy', self::CUSTOMER_ID, self::STORE_ID);
    }

    public function testAUidThatMatchesNeitherIsRefused(): void
    {
        $this->searchResult([]);
        $this->shipmentRepository->method('get')
            ->willThrowException(new NoSuchEntityException(new Phrase('nope')));

        $this->expectException(DocumentUnavailableException::class);

        $this->renderer->loadAndAuthorize(self::UID_ENTITY, self::CUSTOMER_ID, self::STORE_ID);
    }

    /**
     * @dataProvider unusableUids
     */
    public function testAUidThatIsNotBase64IsRefusedBeforeAnyLookup(string $uid): void
    {
        $this->shipmentRepository->expects($this->never())->method('getList');
        $this->shipmentRepository->expects($this->never())->method('get');

        $this->expectException(DocumentUnavailableException::class);

        $this->renderer->loadAndAuthorize($uid, self::CUSTOMER_ID, self::STORE_ID);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableUids(): array
    {
        return [
            'empty' => [''],
            'not base64 at all' => ['!!!!'],
            'broken padding' => ['NA='],
            // Strict base64_decode() accepts this and returns "4". Uid::isValidBase64() rejects it
            // because re-encoding gives "NA==" — which is why the renderer uses that rather than
            // base64_decode($uid, true) on its own.
            'unpadded, which core never emits' => ['NA'],
        ];
    }

    public function testTheGuardSeesTheShipmentsStoreAndTheParentOrder(): void
    {
        $order = $this->createMock(Order::class);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->expects($this->once())->method('get')->with(self::ORDER_ID)->willReturn($order);

        $this->searchResult([$this->shipment(storeId: 3)]);
        $this->guard->expects($this->once())->method('assert')->with($order, 3, self::CUSTOMER_ID, self::STORE_ID);

        $renderer = new ShipmentRenderer(
            $orderRepository,
            $this->guard,
            new Uid(),
            $this->logger,
            $this->shipmentRepository,
            $this->searchCriteriaBuilder,
            $this->createMock(ShipmentPdfFactory::class)
        );

        $renderer->loadAndAuthorize(self::UID_INCREMENT, self::CUSTOMER_ID, self::STORE_ID);
    }

    public function testTheFilenameIsBuiltFromTheIncrementIdNotTheEntityId(): void
    {
        // It is the number printed on the packing slip and the one a customer quotes in an email.
        $this->searchResult([$this->shipment(entityId: 4, incrementId: '000000001')]);

        $document = $this->renderer->loadAndAuthorize(self::UID_INCREMENT, self::CUSTOMER_ID, self::STORE_ID);

        $this->assertSame('shipment-000000001.pdf', $document->filename());
    }

    public function testTheFingerprintCombinesTheShipmentAndItsOrder(): void
    {
        // An address corrected on the order alone changes no shipment timestamp, and the packing
        // slip draws that address — so the order's timestamp has to be in the fingerprint too.
        $order = $this->createMock(Order::class);
        $order->method('getUpdatedAt')->willReturn('2026-08-02 09:00:00');
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($order);

        $this->searchResult([$this->shipment(updatedAt: '2026-08-01 10:00:00')]);

        $renderer = new ShipmentRenderer(
            $orderRepository,
            $this->guard,
            new Uid(),
            $this->logger,
            $this->shipmentRepository,
            $this->searchCriteriaBuilder,
            $this->createMock(ShipmentPdfFactory::class)
        );

        $this->assertSame(
            '2026-08-01 10:00:00|2026-08-02 09:00:00',
            $renderer->loadAndAuthorize(self::UID_INCREMENT, self::CUSTOMER_ID, self::STORE_ID)->fingerprint
        );
    }

    /**
     * @param ShipmentInterface[] $items
     */
    private function searchResult(array $items): void
    {
        $result = $this->createMock(ShipmentSearchResultInterface::class);
        $result->method('getItems')->willReturn($items);
        $this->shipmentRepository->method('getList')->willReturn($result);
    }

    private function shipment(
        int $entityId = 4,
        string $incrementId = '000000001',
        int $storeId = self::STORE_ID,
        string $updatedAt = '2026-08-01 10:00:00'
    ): Shipment&MockObject {
        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getEntityId')->willReturn($entityId);
        $shipment->method('getIncrementId')->willReturn($incrementId);
        $shipment->method('getStoreId')->willReturn($storeId);
        $shipment->method('getOrderId')->willReturn(self::ORDER_ID);
        $shipment->method('getUpdatedAt')->willReturn($updatedAt);

        return $shipment;
    }
}
