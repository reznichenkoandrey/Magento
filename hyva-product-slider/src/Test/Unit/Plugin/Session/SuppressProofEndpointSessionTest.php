<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Test\Unit\Plugin\Session;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Session\SessionStartChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductSlider\Plugin\Session\SuppressProofEndpointSession;

class SuppressProofEndpointSessionTest extends TestCase
{
    private HttpRequest&MockObject $request;
    private SessionStartChecker&MockObject $subject;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->subject = $this->createMock(SessionStartChecker::class);
    }

    public function testTheProofEndpointGetsNoSession(): void
    {
        $this->request->method('getPathInfo')->willReturn('/scr1be_slider/proof/index/');

        $this->assertFalse($this->plugin()->afterCheck($this->subject, true));
    }

    public function testTheProofEndpointIsRecognisedBehindAStoreCode(): void
    {
        // Path info carries the store code on a multi-store install; matching on a substring is how
        // core's own PayPal plugin handles the same problem.
        $this->request->method('getPathInfo')->willReturn('/de/scr1be_slider/proof/index/?ids=1,2');

        $this->assertFalse($this->plugin()->afterCheck($this->subject, true));
    }

    public function testEveryOtherRequestKeepsItsSession(): void
    {
        $this->request->method('getPathInfo')->willReturn('/checkout/cart/');

        $this->assertTrue($this->plugin()->afterCheck($this->subject, true));
    }

    public function testTheAdminSliderScreensKeepTheirSession(): void
    {
        // Same route id, different front name and area. Suppressing the session here would log the
        // merchandiser out of the grid.
        $this->request->method('getPathInfo')->willReturn('/admin/scr1be_slider/slider/edit/slider_id/3/');

        $this->assertTrue($this->plugin()->afterCheck($this->subject, true));
    }

    public function testSomebodyElsesVetoIsNeverOverturned(): void
    {
        // The check is a conjunction: this plugin may add a `false`, never remove one. Core's own
        // implementations of the hook open with the same guard.
        $this->request->expects($this->never())->method('getPathInfo');

        $this->assertFalse($this->plugin()->afterCheck($this->subject, false));
    }

    private function plugin(): SuppressProofEndpointSession
    {
        return new SuppressProofEndpointSession($this->request);
    }
}
