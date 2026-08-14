<?php
declare(strict_types=1);

namespace Scr1be\GuestRegistration\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\GuestRegistration\Model\RegistrationOutcome;
use Scr1be\GuestRegistration\Model\RegistrationResultHolder;

/**
 * A request-scoped singleton is only correct if it is actually emptied between requests, and only
 * useful if it can tell two orders in the same request apart. Both are one line of code and both
 * fail silently.
 */
class RegistrationResultHolderTest extends TestCase
{
    private RegistrationResultHolder $holder;

    protected function setUp(): void
    {
        $this->holder = new RegistrationResultHolder();
    }

    public function testReturnsNullForAnOrderItNeverSaw(): void
    {
        $this->assertNull($this->holder->get('000000001'));
    }

    public function testKeepsOneVerdictPerOrder(): void
    {
        $this->holder->record('000000001', RegistrationOutcome::CREATED);
        $this->holder->record('000000002', RegistrationOutcome::SKIPPED_LOGGED_IN);

        $this->assertSame(RegistrationOutcome::CREATED, $this->holder->get('000000001'));
        $this->assertSame(RegistrationOutcome::SKIPPED_LOGGED_IN, $this->holder->get('000000002'));
    }

    public function testIgnoresAnEmptyIncrementId(): void
    {
        $this->holder->record('', RegistrationOutcome::CREATED);

        $this->assertNull($this->holder->get(''));
    }

    /**
     * Under a persistent application server this is the difference between a correct answer and
     * telling request N+1 about request N's shopper.
     */
    public function testResetStateEmptiesEverything(): void
    {
        $this->holder->record('000000001', RegistrationOutcome::CREATED);

        $this->holder->_resetState();

        $this->assertNull($this->holder->get('000000001'));
    }
}
