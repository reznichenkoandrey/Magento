<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Test\Unit\Model\Document;

use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\SignedDocumentDelivery\Exception\DocumentUnavailableException;
use Scr1be\SignedDocumentDelivery\Model\Document\OwnershipGuard;

class OwnershipGuardTest extends TestCase
{
    private const CUSTOMER_ID = 42;
    private const STORE_ID = 1;

    private LoggerInterface&MockObject $logger;
    private OwnershipGuard $guard;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->guard = new OwnershipGuard($this->logger);
    }

    public function testTheOwnersOwnDocumentInTheirOwnStorePasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard->assert($this->order(self::CUSTOMER_ID), self::STORE_ID, self::CUSTOMER_ID, self::STORE_ID);
    }

    public function testAStringCustomerIdFromTheDatabaseStillMatches(): void
    {
        // sales_order.customer_id comes back as a string through the ORM on most drivers, so the
        // comparison has to cast. A `===` against the raw value would refuse every real request.
        $this->expectNotToPerformAssertions();

        $this->guard->assert($this->order('42'), self::STORE_ID, self::CUSTOMER_ID, self::STORE_ID);
    }

    public function testSomebodyElsesOrderIsRefused(): void
    {
        $this->expectException(DocumentUnavailableException::class);

        $this->guard->assert($this->order(43), self::STORE_ID, self::CUSTOMER_ID, self::STORE_ID);
    }

    public function testAGuestOrderIsRefusedRatherThanComparedAgainstNull(): void
    {
        // (int) null is 0, and a customer id of 0 would never match a real customer — so this
        // happens to be safe by accident. Accidents are not security controls; the null is an
        // explicit branch.
        $this->expectException(DocumentUnavailableException::class);

        $this->guard->assert($this->order(null), self::STORE_ID, self::CUSTOMER_ID, self::STORE_ID);
    }

    public function testTheOwnersOwnDocumentFromAnotherStoreViewIsRefused(): void
    {
        // A customer account is shared across every store view of a website, so ownership alone
        // would hand a store-A request the paperwork rendered for store B — different logo,
        // different address block, on a multi-brand install a different brand.
        $this->expectException(DocumentUnavailableException::class);

        $this->guard->assert($this->order(self::CUSTOMER_ID), 2, self::CUSTOMER_ID, self::STORE_ID);
    }

    public function testEveryRefusalCarriesItsReasonToTheLogAndNotToTheClient(): void
    {
        $logged = null;
        $this->logger->expects($this->once())
            ->method('warning')
            ->willReturnCallback(function (string $message) use (&$logged): void {
                $logged = $message;
            });

        try {
            $this->guard->assert($this->order(43), self::STORE_ID, self::CUSTOMER_ID, self::STORE_ID);
            $this->fail('expected the guard to refuse');
        } catch (DocumentUnavailableException $e) {
            $this->assertSame('The requested document is not available.', $e->getMessage());
        }

        $this->assertStringContainsString('belongs to customer 43', (string) $logged);
    }

    public function testAllThreeRefusalsLookIdenticalFromOutside(): void
    {
        // No oracle: "no such document", "not yours" and "wrong store" have to be one answer, or a
        // client can walk the id space and read the difference.
        $messages = [];

        foreach ([[43, self::STORE_ID], [null, self::STORE_ID], [self::CUSTOMER_ID, 2]] as [$owner, $documentStore]) {
            try {
                $this->guard->assert($this->order($owner), $documentStore, self::CUSTOMER_ID, self::STORE_ID);
            } catch (DocumentUnavailableException $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertCount(3, $messages);
        $this->assertCount(1, array_unique($messages));
    }

    private function order(int|string|null $customerId): OrderInterface&MockObject
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomerId')->willReturn($customerId);
        $order->method('getEntityId')->willReturn(1000);

        return $order;
    }
}
