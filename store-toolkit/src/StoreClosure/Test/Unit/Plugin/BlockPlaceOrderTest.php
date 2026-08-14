<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\Test\Unit\Plugin;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreClosure\Model\ClosureState;
use Scr1be\StoreClosure\Plugin\BlockPlaceOrder;

class BlockPlaceOrderTest extends TestCase
{
    /**
     * @var ClosureState&MockObject
     */
    private $closureState;

    /**
     * @var CartRepositoryInterface&MockObject
     */
    private $cartRepository;

    /**
     * @var StoreManagerInterface&MockObject
     */
    private $storeManager;

    /**
     * @var CartManagementInterface&MockObject
     */
    private $subject;

    private BlockPlaceOrder $plugin;

    protected function setUp(): void
    {
        $this->closureState = $this->createMock(ClosureState::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->subject = $this->createMock(CartManagementInterface::class);

        $this->plugin = new BlockPlaceOrder($this->closureState, $this->cartRepository, $this->storeManager);
    }

    public function testAnOpenStorePassesTheArgumentsThrough(): void
    {
        $this->givenQuoteInStore(7);
        $this->closureState->method('isClosed')->willReturn(false);

        $payment = $this->createMock(PaymentInterface::class);

        self::assertSame([15, $payment], $this->plugin->beforePlaceOrder($this->subject, 15, $payment));
    }

    public function testAClosedStoreRefusesTheOrder(): void
    {
        $this->givenQuoteInStore(7);
        $this->closureState->method('isClosed')->willReturn(true);

        $this->expectException(CouldNotSaveException::class);

        $this->plugin->beforePlaceOrder($this->subject, 15);
    }

    public function testTheQuotesStoreDecidesRatherThanTheCurrentOne(): void
    {
        // An API client picks its own store scope — a URL segment for REST, a header for GraphQL.
        // A cart created against the closed store and submitted under an open store's scope would
        // otherwise walk straight through the closure.
        $this->givenQuoteInStore(7);
        $this->storeManager->expects(self::never())->method('getStore');

        $this->closureState->expects(self::once())->method('isClosed')->with(7)->willReturn(false);

        $this->plugin->beforePlaceOrder($this->subject, 15);
    }

    public function testAMissingQuoteStillGetsChecked(): void
    {
        $this->cartRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('No such cart.')));

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(4);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->closureState->expects(self::once())->method('isClosed')->with(4)->willReturn(true);

        $this->expectException(CouldNotSaveException::class);

        $this->plugin->beforePlaceOrder($this->subject, 999);
    }

    public function testANullPaymentSurvivesTheRoundTrip(): void
    {
        $this->givenQuoteInStore(7);
        $this->closureState->method('isClosed')->willReturn(false);

        self::assertSame([15, null], $this->plugin->beforePlaceOrder($this->subject, 15));
    }

    private function givenQuoteInStore(int $storeId): void
    {
        $quote = $this->createMock(CartInterface::class);
        $quote->method('getStoreId')->willReturn($storeId);

        $this->cartRepository->method('get')->willReturn($quote);
    }
}
