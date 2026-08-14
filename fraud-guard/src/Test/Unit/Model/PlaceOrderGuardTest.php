<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Test\Unit\Model;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Quote\Api\Data\CartInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\FraudGuard\Model\Config;
use Scr1be\FraudGuard\Model\FlagResolver;
use Scr1be\FraudGuard\Model\GuardLog;
use Scr1be\FraudGuard\Model\PlaceOrderGuard;

class PlaceOrderGuardTest extends TestCase
{
    private const STORE_ID = 3;
    private const CUSTOMER_ID = 42;

    private Config&MockObject $config;
    private FlagResolver&MockObject $flagResolver;
    private GuardLog&MockObject $log;
    private State&MockObject $appState;
    private PlaceOrderGuard $guard;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->flagResolver = $this->createMock(FlagResolver::class);
        $this->log = $this->createMock(GuardLog::class);
        $this->appState = $this->createMock(State::class);

        $this->guard = new PlaceOrderGuard(
            $this->config,
            $this->flagResolver,
            $this->log,
            $this->appState
        );
    }

    public function testDeclinesAFlaggedCustomerWithTheConfiguredCopy(): void
    {
        $quote = $this->quote(self::CUSTOMER_ID);
        $this->enableGuard();
        $this->appState->method('getAreaCode')->willReturn(Area::AREA_GRAPHQL);
        $this->config->method('getDeclineMessage')->with(self::STORE_ID)->willReturn('Card declined.');
        $this->flagResolver->method('isFlagged')->with(self::CUSTOMER_ID)->willReturn(true);
        $this->log->expects($this->once())->method('blockedAttempt')->with($quote, self::CUSTOMER_ID);

        // CommandException is the class Magento\Payment throws on a real gateway decline; that
        // identity is the module's headline promise, so the test asserts the type, not just a throw.
        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('Card declined.');

        $this->guard->assertNotFlagged($quote);
    }

    public function testTheKillSwitchShortCircuitsBeforeAnyLookup(): void
    {
        $this->config->method('isEnabled')->with(self::STORE_ID)->willReturn(false);
        $this->flagResolver->expects($this->never())->method('isFlagged');
        $this->appState->expects($this->never())->method('getAreaCode');

        $this->guard->assertNotFlagged($this->quote(self::CUSTOMER_ID));
    }

    public function testAdminOrderCreationIsExempt(): void
    {
        $this->enableGuard();
        $this->appState->method('getAreaCode')->willReturn(Area::AREA_ADMINHTML);
        $this->flagResolver->expects($this->never())->method('isFlagged');

        $this->guard->assertNotFlagged($this->quote(self::CUSTOMER_ID));
    }

    public function testAnUnresolvedAreaKeepsTheGuardOn(): void
    {
        $this->enableGuard();
        $this->appState->method('getAreaCode')
            ->willThrowException(new LocalizedException(new Phrase('Area code is not set')));
        $this->flagResolver->method('isFlagged')->willReturn(true);

        $this->expectException(CommandException::class);

        $this->guard->assertNotFlagged($this->quote(self::CUSTOMER_ID));
    }

    /**
     * The documented guest gap: no customer entity means no flag to read.
     */
    public function testGuestQuotesPassThrough(): void
    {
        $this->enableGuard();
        $this->appState->method('getAreaCode')->willReturn(Area::AREA_FRONTEND);
        $this->flagResolver->expects($this->never())->method('isFlagged');

        $this->guard->assertNotFlagged($this->quote(null));
    }

    public function testAnUnflaggedCustomerIsNeverLogged(): void
    {
        $this->enableGuard();
        $this->appState->method('getAreaCode')->willReturn(Area::AREA_FRONTEND);
        $this->flagResolver->method('isFlagged')->willReturn(false);
        $this->log->expects($this->never())->method('blockedAttempt');

        $this->guard->assertNotFlagged($this->quote(self::CUSTOMER_ID));
    }

    private function enableGuard(): void
    {
        $this->config->method('isEnabled')->with(self::STORE_ID)->willReturn(true);
    }

    private function quote(?int $customerId): CartInterface&MockObject
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($customerId);

        $quote = $this->createMock(CartInterface::class);
        $quote->method('getStoreId')->willReturn(self::STORE_ID);
        $quote->method('getCustomer')->willReturn($customer);

        return $quote;
    }
}
