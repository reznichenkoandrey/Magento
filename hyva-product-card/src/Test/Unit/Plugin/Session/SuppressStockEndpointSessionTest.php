<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Test\Unit\Plugin\Session;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Session\SessionStartChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductCard\Plugin\Session\SuppressStockEndpointSession;

class SuppressStockEndpointSessionTest extends TestCase
{
    private HttpRequest&MockObject $request;
    private SessionStartChecker&MockObject $subject;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->subject = $this->createMock(SessionStartChecker::class);
    }

    public function testTheStockEndpointGetsNoSession(): void
    {
        $this->request->method('getPathInfo')->willReturn('/scr1be_card/stock/status/');

        $this->assertFalse($this->plugin()->afterCheck($this->subject, true));
    }

    public function testTheStockEndpointIsRecognisedBehindAStoreCode(): void
    {
        // Path info carries the store code on a multi-store install; matching on a substring is how
        // core's own PayPal plugin handles the same problem.
        $this->request->method('getPathInfo')->willReturn('/de/scr1be_card/stock/status/?ids=1,2');

        $this->assertFalse($this->plugin()->afterCheck($this->subject, true));
    }

    public function testEveryOtherRequestKeepsItsSession(): void
    {
        $this->request->method('getPathInfo')->willReturn('/checkout/cart/');

        $this->assertTrue($this->plugin()->afterCheck($this->subject, true));
    }

    public function testTheMessageDrainEndpointKeepsItsSessionBecauseItIsTheWholePoint(): void
    {
        // Draining flash messages means reading the session. Suppressing it there would make the
        // endpoint answer "no messages" forever.
        $this->request->method('getPathInfo')->willReturn('/scr1be_card/message/drain/');

        $this->assertTrue($this->plugin()->afterCheck($this->subject, true));
    }

    public function testSomebodyElsesVetoIsNeverOverturned(): void
    {
        // The check is a conjunction: this plugin may add a `false`, never remove one. Core's own
        // two implementations of the hook open with the same guard.
        $this->request->expects($this->never())->method('getPathInfo');

        $this->assertFalse($this->plugin()->afterCheck($this->subject, false));
    }

    private function plugin(): SuppressStockEndpointSession
    {
        return new SuppressStockEndpointSession($this->request);
    }
}
