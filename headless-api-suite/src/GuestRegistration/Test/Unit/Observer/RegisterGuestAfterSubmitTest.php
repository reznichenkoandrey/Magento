<?php
declare(strict_types=1);

namespace Scr1be\GuestRegistration\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\GuestRegistration\Model\GuestRegistrar;
use Scr1be\GuestRegistration\Model\RegistrationOutcome;
use Scr1be\GuestRegistration\Model\RegistrationResultHolder;
use Scr1be\GuestRegistration\Observer\RegisterGuestAfterSubmit;

/**
 * The observer is three guards and a hand-off, and each guard exists because the event payload is
 * not under this module's control.
 */
class RegisterGuestAfterSubmitTest extends TestCase
{
    private GuestRegistrar&MockObject $registrar;
    private RegistrationResultHolder $holder;
    private RegisterGuestAfterSubmit $observer;

    protected function setUp(): void
    {
        $this->registrar = $this->createMock(GuestRegistrar::class);
        $this->holder = new RegistrationResultHolder();
        $this->observer = new RegisterGuestAfterSubmit($this->registrar, $this->holder);
    }

    public function testRecordsTheVerdictAgainstTheIncrementId(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000123');

        $this->registrar->expects($this->once())
            ->method('register')
            ->with($order)
            ->willReturn(RegistrationOutcome::CREATED);

        $this->observer->execute($this->observerFor(['order' => $order]));

        $this->assertSame(RegistrationOutcome::CREATED, $this->holder->get('000000123'));
    }

    public function testIgnoresAnEventWithoutAnOrder(): void
    {
        $this->registrar->expects($this->never())->method('register');

        $this->observer->execute($this->observerFor(['quote' => new DataObject()]));
    }

    /**
     * `sales_model_service_quote_submit_success` is a public event: another module may re-dispatch
     * it, and `order` is only an OrderInterface by convention.
     */
    public function testIgnoresAnOrderKeyThatIsNotAnOrder(): void
    {
        $this->registrar->expects($this->never())->method('register');

        $this->observer->execute($this->observerFor(['order' => new DataObject(['increment_id' => '1'])]));
    }

    /**
     * The increment id is the only handle the resolver plugin has. Without one there is nothing to
     * file the verdict under, and running the ladder would create an account nobody could be told
     * about.
     */
    public function testDoesNothingWhenTheOrderHasNoIncrementId(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn(null);

        $this->registrar->expects($this->never())->method('register');

        $this->observer->execute($this->observerFor(['order' => $order]));
    }

    /**
     * @param array<string, mixed> $data
     * @return Observer
     */
    private function observerFor(array $data): Observer
    {
        $event = new Event($data);

        return new Observer(['event' => $event]);
    }
}
