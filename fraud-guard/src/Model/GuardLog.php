<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Model;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Quote\Api\Data\CartInterface;
use Psr\Log\LoggerInterface;

/**
 * The forensic half of the module.
 *
 * The shopper-facing half is deliberately silent, so the log is the *only* place a blocked
 * attempt is visible. It gets its own file (`var/log/fraud_guard.log`, wired in di.xml) rather
 * than a line in system.log: a merchant investigating card testing wants a file they can tail,
 * and an ops team shipping logs to a SIEM wants one path to watch.
 *
 * IP and user agent are recorded because they are the two fields that make a series of attempts
 * legible as a series. They are merchant-side data about a request the merchant served — the same
 * data the access log already holds — not an extra profile of the customer.
 */
class GuardLog
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Config $config,
        private readonly RemoteAddress $remoteAddress,
        private readonly HttpRequest $request
    ) {
    }

    public function blockedAttempt(CartInterface $quote, int $customerId): void
    {
        $storeId = $quote->getStoreId() === null ? null : (int) $quote->getStoreId();
        if (!$this->config->isAttemptLoggingEnabled($storeId)) {
            return;
        }

        $customer = $quote->getCustomer();

        $this->logger->warning(
            'Declined a place-order attempt from a flagged customer',
            [
                'customer_id' => $customerId,
                'customer_email' => $customer === null ? null : $customer->getEmail(),
                'quote_id' => $quote->getId(),
                'store_id' => $storeId,
                'items_count' => $quote->getItemsCount(),
                'ip' => $this->remoteAddress->getRemoteAddress(),
                'user_agent' => $this->readUserAgent(),
            ]
        );
    }

    /**
     * Recorded whenever the guard could not decide. Not gated by the attempt-logging setting:
     * a merchant who turned attempt logging off asked for less noise about carders, not for
     * silence about a guard that stopped working.
     */
    public function lookupFailed(int $customerId, \Throwable $error): void
    {
        $this->logger->error(
            'Could not resolve the fraud flag; the order was allowed to proceed',
            [
                'customer_id' => $customerId,
                'exception' => (string) $error,
            ]
        );
    }

    private function readUserAgent(): ?string
    {
        $userAgent = $this->request->getHeader('User-Agent');

        return is_string($userAgent) && $userAgent !== '' ? $userAgent : null;
    }
}
