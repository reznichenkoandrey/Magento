<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Plugin\Session;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Session\SessionStartChecker;

/**
 * Keeps the stock endpoint out of the session, and therefore inside the CDN.
 *
 * `SessionManager::start()` opens with `if ($this->sessionStartChecker->check())` and does nothing
 * at all when that returns false — no `session_start()`, no `renewCookie()`. Returning false for
 * one route is how core itself keeps sessions out of places they would do harm:
 * `Magento\GraphQl\Plugin\DisableSession` does it for the GraphQL area, and
 * `Magento\Paypal\Plugin\TransparentSessionChecker` does it for four PayPal return URLs by matching
 * `$request->getPathInfo()`. This is the same plugin, aimed at one path.
 *
 * Without it the endpoint would set `PHPSESSID`, and — the more expensive half — a non-empty HTTP
 * context would make `Response\Http::sendVary()` write an `X-Magento-Vary` cookie. The shipped
 * `varnish7.vcl` refuses to cache a response that sets that cookie when the request did not send
 * one, so the first guest to hit the endpoint would poison it into a hit-for-pass.
 *
 * `after` rather than `around`: the answer is a conjunction of everyone's veto, and core's own
 * plugins are written the same way — check the running result first, and never turn someone else's
 * `false` back into `true`.
 */
class SuppressStockEndpointSession
{
    /**
     * Matched against the path info rather than the resolved action name, because routing has not
     * necessarily run yet when the first `start()` happens.
     */
    private const STOCK_ENDPOINT_PATH = 'scr1be_card/stock/status';

    public function __construct(private readonly HttpRequest $request)
    {
    }

    public function afterCheck(SessionStartChecker $subject, bool $result): bool
    {
        if ($result === false) {
            return false;
        }

        return !str_contains((string) $this->request->getPathInfo(), self::STOCK_ENDPOINT_PATH);
    }
}
