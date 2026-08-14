<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Test\Unit\Plugin\Quote;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CustomerGroupGuard\Model\PlaceOrderGuard;
use Scr1be\CustomerGroupGuard\Plugin\Quote\BlockStaleGroupPlaceOrder;

class BlockStaleGroupPlaceOrderTest extends TestCase
{
    private PlaceOrderGuard&MockObject $guard;
    private CartRepositoryInterface&MockObject $cartRepository;
    private BlockStaleGroupPlaceOrder $plugin;

    protected function setUp(): void
    {
        $this->guard = $this->createMock(PlaceOrderGuard::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->plugin = new BlockStaleGroupPlaceOrder($this->guard, $this->cartRepository);
    }

    /**
     * The cart id arrives as a string on the REST path and as an int from PHP callers, so the
     * cast is part of the contract rather than a tidy-up.
     */
    public function testHandsTheLoadedQuoteToTheGuard(): void
    {
        $quote = $this->createMock(CartInterface::class);

        // get(), not getActive(): whether a cart is still active is core's judgement to make.
        $this->cartRepository->expects($this->once())->method('get')->with(42)->willReturn($quote);
        $this->guard->expects($this->once())->method('assertGroupIsCurrent')->with($quote);

        $this->plugin->beforePlaceOrder($this->createMock(CartManagementInterface::class), '42');
    }

    public function testAnUnknownCartIsLeftToCore(): void
    {
        $this->cartRepository->method('get')
            ->willThrowException(new NoSuchEntityException(new Phrase('no cart')));

        $this->guard->expects($this->never())->method('assertGroupIsCurrent');

        // No exception of this module's own: core raises its own "cart not found" on the very
        // next line, and replacing it with a message about pricing would be a lie.
        $this->plugin->beforePlaceOrder($this->createMock(CartManagementInterface::class), 42);
    }
}
