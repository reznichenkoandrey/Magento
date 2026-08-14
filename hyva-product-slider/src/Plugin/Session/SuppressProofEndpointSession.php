<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Plugin\Session;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Session\SessionStartChecker;

/**
 * Keeps the proof endpoint out of the session, and therefore inside the CDN.
 *
 * `SessionStartChecker::check()` is the single gate `SessionManager::start()` consults, and returning
 * false for one route is how core itself keeps sessions out of places they would do harm:
 * `Magento\GraphQl\Plugin\DisableSession` does it for the GraphQL area, and
 * `Magento\Paypal\Plugin\TransparentSessionChecker` does it for four PayPal return URLs by matching
 * `$request->getPathInfo()`. This is the same plugin, aimed at one path.
 *
 * Without it the endpoint would set `PHPSESSID` and, more expensively, a non-empty HTTP context would
 * make `Response\Http::sendVary()` write an `X-Magento-Vary` cookie — which the shipped
 * `varnish7.vcl` treats as a reason to mark the response uncacheable when the request did not send
 * one. The first guest to touch the endpoint would turn it into a hit-for-pass for everybody.
 *
 * `after` rather than `around`: the answer is a conjunction of everyone's veto. Turning somebody
 * else's `false` back into `true` is the one thing a plugin here must never do.
 */
class SuppressProofEndpointSession
{
    /**
     * Matched against the path info rather than the resolved action name, because routing has not
     * necessarily run yet when the first `start()` happens.
     */
    private const PROOF_ENDPOINT_PATH = 'scr1be_slider/proof';

    public function __construct(private readonly HttpRequest $request)
    {
    }

    public function afterCheck(SessionStartChecker $subject, bool $result): bool
    {
        if ($result === false) {
            return false;
        }

        return !str_contains((string) $this->request->getPathInfo(), self::PROOF_ENDPOINT_PATH);
    }
}
