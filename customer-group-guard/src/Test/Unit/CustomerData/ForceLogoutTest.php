<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Test\Unit\CustomerData;

use Magento\Customer\Model\Session as CustomerSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CustomerGroupGuard\CustomerData\ForceLogout;
use Scr1be\CustomerGroupGuard\Model\Config;
use Scr1be\CustomerGroupGuard\Model\GroupCookie;
use Scr1be\CustomerGroupGuard\Model\GroupResolver;

class ForceLogoutTest extends TestCase
{
    private Config&MockObject $config;
    private CustomerSession&MockObject $customerSession;
    private GroupCookie&MockObject $groupCookie;
    private GroupResolver&MockObject $groupResolver;
    private ForceLogout $section;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->customerSession = $this->createMock(CustomerSession::class);
        $this->groupCookie = $this->createMock(GroupCookie::class);
        $this->groupResolver = $this->createMock(GroupResolver::class);

        $this->config->method('isForceLogoutEnabled')->willReturn(true);
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->customerSession->method('getCustomerId')->willReturn(15);

        $this->section = new ForceLogout(
            $this->config,
            $this->customerSession,
            $this->groupCookie,
            $this->groupResolver
        );
    }

    public function testAsksForALogoutWhenTheGroupChangedUnderTheSession(): void
    {
        $this->groupCookie->method('read')->willReturn(1);
        $this->groupResolver->method('resolveStoredGroupId')->with(15)->willReturn(4);

        $data = $this->section->getSectionData();

        $this->assertTrue($data['force_logout']);
        $this->assertArrayHasKey('message', $data);
        $this->assertNotSame('', $data['message']);
    }

    /**
     * Section data lands in localStorage, so it says that something changed and never which
     * group the customer was in or is in now.
     */
    public function testThePayloadNeverCarriesAGroupId(): void
    {
        $this->groupCookie->method('read')->willReturn(1);
        $this->groupResolver->method('resolveStoredGroupId')->willReturn(4);

        $this->assertSame(['force_logout', 'message'], array_keys($this->section->getSectionData()));
    }

    public function testStaysQuietWhileTheGroupStillMatches(): void
    {
        $this->groupCookie->method('read')->willReturn(4);
        $this->groupResolver->method('resolveStoredGroupId')->willReturn(4);

        $this->assertSame(['force_logout' => false], $this->section->getSectionData());
    }

    public function testStaysQuietWhenTheSoftPathIsSwitchedOff(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isForceLogoutEnabled')->willReturn(false);
        $this->groupCookie->expects($this->never())->method('read');

        $section = new ForceLogout(
            $config,
            $this->customerSession,
            $this->groupCookie,
            $this->groupResolver
        );

        $this->assertSame(['force_logout' => false], $section->getSectionData());
    }

    public function testStaysQuietForAGuest(): void
    {
        $session = $this->createMock(CustomerSession::class);
        $session->method('isLoggedIn')->willReturn(false);
        $this->groupCookie->expects($this->never())->method('read');

        $section = new ForceLogout(
            $this->config,
            $session,
            $this->groupCookie,
            $this->groupResolver
        );

        $this->assertSame(['force_logout' => false], $section->getSectionData());
    }

    /**
     * The healing write. A missing cookie is "unknown", and the alternative reading of unknown
     * signs out every logged-in customer the moment the module is deployed.
     */
    public function testRecordsTheSessionGroupWhenTheCookieIsMissing(): void
    {
        $this->groupCookie->method('read')->willReturn(null);
        $this->customerSession->method('getCustomerGroupId')->willReturn(3);

        $this->groupCookie->expects($this->once())->method('write')->with(3);
        $this->groupResolver->expects($this->never())->method('resolveStoredGroupId');

        $this->assertSame(['force_logout' => false], $this->section->getSectionData());
    }

    /**
     * An unreadable customer record must never become a logout.
     */
    public function testStaysQuietWhenTheStoredGroupIsUnknown(): void
    {
        $this->groupCookie->method('read')->willReturn(1);
        $this->groupResolver->method('resolveStoredGroupId')->willReturn(null);

        $this->assertSame(['force_logout' => false], $this->section->getSectionData());
    }
}
