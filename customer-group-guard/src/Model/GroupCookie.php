<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Model;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfigInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\CookieSizeLimitReachedException;
use Magento\Framework\Stdlib\Cookie\FailureToSendException;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The record of which customer group this browser has been served under.
 *
 * Written once, at login. Not rewritten while the session runs, which is the whole reason the
 * value is useful: it is a statement about the *pages the browser is holding* — the ones in the
 * back/forward cache, the ones the CDN has under this session's X-Magento-Vary hash, the prices
 * printed on all of them — and not a statement about the customer record. Core rewrites the
 * session's own group id in several places, and once it has, session and database agree while
 * every cached page still belongs to the group before the change.
 *
 * Three properties are worth stating, because each of them is a decision:
 *
 * - It is a *sensitive* cookie. The framework's sensitive-cookie metadata guarantees HttpOnly
 *   and sets Secure from the current request, so neither is an option this module could get
 *   wrong. Nothing in the browser reads the value — the comparison happens in PHP, in the
 *   section source — so there is no reason for script to be able to touch it.
 * - Path and domain come from the session configuration, so the cookie is scoped exactly like
 *   the session cookie it shadows. Get that wrong on a multi-domain installation and the write
 *   succeeds while the read, and worse the delete, silently do not.
 * - The value is untrusted. It arrives from the browser, so it is validated as digits and
 *   anything else reads as absent. Group 0 is a real group id in Magento (NOT LOGGED IN), which
 *   makes a lenient (int) cast on garbage a comparison that can accidentally succeed.
 */
class GroupCookie
{
    /**
     * Short and unprefixed by design: on a CDN-fronted installation this name has to be added to
     * a cookie allowlist by hand, and a name nobody can type is a name nobody adds.
     */
    public const COOKIE_NAME = 'scr1be_group';

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly SessionConfigInterface $sessionConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return int|null null when the cookie is absent or does not hold a group id.
     */
    public function read(): ?int
    {
        $raw = $this->cookieManager->getCookie(self::COOKIE_NAME);

        return is_string($raw) && $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    }

    /**
     * Failures are logged and swallowed. A cookie this module could not write is a soft path that
     * will not fire; a login this module broke is an outage. The two are not close enough to
     * treat the same way.
     */
    public function write(int $groupId): void
    {
        try {
            $metadata = $this->cookieMetadataFactory->createSensitiveCookieMetadata()
                ->setPath($this->sessionConfig->getCookiePath())
                ->setDomain($this->sessionConfig->getCookieDomain());

            $this->cookieManager->setSensitiveCookie(self::COOKIE_NAME, (string) $groupId, $metadata);
        } catch (InputException | CookieSizeLimitReachedException | FailureToSendException $error) {
            $this->logger->warning(
                'scr1be_customer_group_guard: could not record the login group cookie',
                ['group_id' => $groupId, 'exception' => $error]
            );
        }
    }

    /**
     * Deleted on logout regardless of configuration. A cookie left behind after the feature is
     * switched off outlives the code that understands it, and the next time somebody switches the
     * feature back on it is a stale comparison waiting to sign a customer out for no reason.
     */
    public function clear(): void
    {
        try {
            $metadata = $this->cookieMetadataFactory->createCookieMetadata()
                ->setPath($this->sessionConfig->getCookiePath())
                ->setDomain($this->sessionConfig->getCookieDomain());

            $this->cookieManager->deleteCookie(self::COOKIE_NAME, $metadata);
        } catch (InputException | FailureToSendException $error) {
            $this->logger->warning(
                'scr1be_customer_group_guard: could not clear the login group cookie',
                ['exception' => $error]
            );
        }
    }
}
