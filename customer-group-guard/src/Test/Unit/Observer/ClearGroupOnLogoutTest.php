<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CustomerGroupGuard\Model\GroupCookie;
use Scr1be\CustomerGroupGuard\Observer\ClearGroupOnLogout;

class ClearGroupOnLogoutTest extends TestCase
{
    private GroupCookie&MockObject $groupCookie;
    private ClearGroupOnLogout $observer;

    protected function setUp(): void
    {
        $this->groupCookie = $this->createMock(GroupCookie::class);
        $this->observer = new ClearGroupOnLogout($this->groupCookie);
    }

    /**
     * No configuration is consulted on the way out. A cookie that outlives the setting that
     * created it is a value nothing maintains and everything reads again the moment the setting
     * comes back on — which is why this observer takes no Config at all.
     */
    public function testAlwaysClearsTheCookie(): void
    {
        $this->groupCookie->expects($this->once())->method('clear');

        $this->observer->execute(new Observer(['event' => new Event()]));
    }
}
