<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\CustomerData;

use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Scr1be\CustomerGroupGuard\Model\Config;
use Scr1be\CustomerGroupGuard\Model\GroupCookie;
use Scr1be\CustomerGroupGuard\Model\GroupResolver;

/**
 * The soft path's decision, answered on an uncacheable request and delivered to the browser
 * through the same private-content channel the mini-cart uses.
 *
 * The payload is deliberately two keys wide. Section data lands in the browser's localStorage
 * and stays there until it is invalidated, so it is the wrong place for anything that describes
 * the account: this section says that something changed and what to tell the shopper, and never
 * which group they were in or which they are in now.
 *
 * The healing write in the middle of the ladder is the one side effect in a read path, and it
 * earns its place. A missing cookie means "unknown", and unknown has exactly two possible
 * readings: sign the customer out, or record what the browser is currently being served under
 * and start comparing from here. The first reading logs out every logged-in customer the moment
 * the module is deployed, and every customer whose browser restarted since. The second costs one
 * comparison cycle and is correct from then on. The value written is the *session's* group,
 * because that is what the pages this browser holds were rendered under — if the session itself
 * is already stale, the next section load sees it and the soft path fires one cycle later.
 */
class ForceLogout implements SectionSourceInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CustomerSession $customerSession,
        private readonly GroupCookie $groupCookie,
        private readonly GroupResolver $groupResolver
    ) {
    }

    /**
     * @return array{force_logout: bool, message?: string}
     */
    public function getSectionData(): array
    {
        if (!$this->config->isForceLogoutEnabled()) {
            return $this->quiet();
        }

        if (!$this->customerSession->isLoggedIn()) {
            // The cookie is deleted on logout, and a guest has no group to go stale.
            return $this->quiet();
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        if ($customerId === 0) {
            return $this->quiet();
        }

        $servedUnder = $this->groupCookie->read();
        if ($servedUnder === null) {
            $this->groupCookie->write((int) $this->customerSession->getCustomerGroupId());

            return $this->quiet();
        }

        $stored = $this->groupResolver->resolveStoredGroupId($customerId);
        if ($stored === null || $stored === $servedUnder) {
            return $this->quiet();
        }

        // One literal, on one line: i18n:collect-phrases parses __() statically, and a
        // concatenated argument is a phrase that never reaches a translator.
        $notice = __('You have been signed out because your customer group changed. Please sign in again.');

        return ['force_logout' => true, 'message' => (string) $notice];
    }

    /**
     * @return array{force_logout: bool}
     */
    private function quiet(): array
    {
        return ['force_logout' => false];
    }
}
