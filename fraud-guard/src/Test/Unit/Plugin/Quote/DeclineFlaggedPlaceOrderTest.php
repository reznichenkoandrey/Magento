<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Test\Unit\Plugin\Quote;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\FraudGuard\Model\PlaceOrderGuard;
use Scr1be\FraudGuard\Plugin\Quote\DeclineFlaggedPlaceOrder;

class DeclineFlaggedPlaceOrderTest extends TestCase
{
    private PlaceOrderGuard&MockObject $guard;
    private CartRepositoryInterface&MockObject $cartRepository;
    private DeclineFlaggedPlaceOrder $plugin;

    protected function setUp(): void
    {
        $this->guard = $this->createMock(PlaceOrderGuard::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->plugin = new DeclineFlaggedPlaceOrder($this->guard, $this->cartRepository);
    }

    public function testHandsTheLoadedQuoteToTheGuard(): void
    {
        $quote = $this->createMock(CartInterface::class);
        // get(), not getActive(): cart state is core's call, not the guard's.
        $this->cartRepository->expects($this->once())->method('get')->with(17)->willReturn($quote);
        $this->guard->expects($this->once())->method('assertNotFlagged')->with($quote);

        $this->plugin->beforePlaceOrder($this->createMock(CartManagementInterface::class), '17');
    }

    public function testAnUnknownCartIsLeftToCore(): void
    {
        $this->cartRepository->method('get')
            ->willThrowException(new NoSuchEntityException(new Phrase('no cart')));
        $this->guard->expects($this->never())->method('assertNotFlagged');

        // No exception of our own: core raises its own "cart not found" a moment later, and
        // replacing it with a fraud decline would leak the guard's existence on a plain 404.
        $this->plugin->beforePlaceOrder($this->createMock(CartManagementInterface::class), 17);
    }
}
