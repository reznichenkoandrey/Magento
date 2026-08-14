<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Scr1be\CustomerGroupGuard\Model\GroupCookie;

/**
 * Clears the record on the way out, unconditionally.
 *
 * There is no configuration check here and that is deliberate. Switching the guard off has to
 * stop it acting, not stop it tidying up: a cookie that outlives the setting is a value nothing
 * maintains and everything still reads the moment the setting comes back on. The delete is also
 * what closes the loop on the soft path — the logout the section asked for is the logout that
 * removes the reason for it.
 */
class ClearGroupOnLogout implements ObserverInterface
{
    public function __construct(
        private readonly GroupCookie $groupCookie
    ) {
    }

    public function execute(Observer $observer): void
    {
        $this->groupCookie->clear();
    }
}
