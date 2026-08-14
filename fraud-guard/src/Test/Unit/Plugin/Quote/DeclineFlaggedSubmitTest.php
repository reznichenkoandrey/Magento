<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Test\Unit\Plugin\Quote;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Framework\Phrase;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\FraudGuard\Model\PlaceOrderGuard;
use Scr1be\FraudGuard\Plugin\Quote\DeclineFlaggedSubmit;

class DeclineFlaggedSubmitTest extends TestCase
{
    private PlaceOrderGuard&MockObject $guard;
    private DeclineFlaggedSubmit $plugin;

    protected function setUp(): void
    {
        $this->guard = $this->createMock(PlaceOrderGuard::class);
        $this->plugin = new DeclineFlaggedSubmit($this->guard);
    }

    public function testGuardsTheQuoteItWasHanded(): void
    {
        $quote = $this->createMock(Quote::class);
        $this->guard->expects($this->once())->method('assertNotFlagged')->with($quote);

        $this->plugin->beforeSubmit($this->createMock(QuoteManagement::class), $quote);
    }

    public function testDoesNotSwallowTheDecline(): void
    {
        // A `before` plugin cannot catch anything by construction, but the assertion is cheap and
        // documents that direct-submit callers get the same decline as the checkout path.
        $this->guard->method('assertNotFlagged')
            ->willThrowException(new CommandException(new Phrase('Card declined.')));

        $this->expectException(CommandException::class);

        $this->plugin->beforeSubmit(
            $this->createMock(QuoteManagement::class),
            $this->createMock(Quote::class)
        );
    }
}
