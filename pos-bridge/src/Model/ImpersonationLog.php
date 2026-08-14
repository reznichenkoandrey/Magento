<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Psr\Log\LoggerInterface;

/**
 * Who acted as whom, and from where.
 *
 * This is the one part of the module that exists for someone who is not the operator. An
 * impersonation endpoint turns "admin with customer-management rights" into "can transact as any
 * shopper", and the answer to "who ran that order" has to be findable months later, from outside
 * the application, by someone reading a file. That is why it gets its own path
 * (`var/log/scr1be_pos_bridge.log`, wired in di.xml) rather than a line in system.log: an audit
 * trail interleaved with cron warnings is an audit trail nobody reads.
 *
 * The acting user comes from `UserContextInterface`, which in the `webapi_rest` area resolves
 * through core's `CompositeUserContext` to the token or OAuth consumer that authenticated the call.
 * Refusals are recorded too — a run of refused attempts is the shape a stolen terminal token makes.
 */
class ImpersonationLog
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly UserContextInterface $userContext,
        private readonly RemoteAddress $remoteAddress
    ) {
    }

    public function issued(int $customerId, string $expiresAt): void
    {
        $this->logger->info(
            'Issued an impersonation token',
            $this->context($customerId) + ['expires_at' => $expiresAt]
        );
    }

    public function refused(int $customerId, string $reason): void
    {
        $this->logger->warning(
            'Refused an impersonation request',
            $this->context($customerId) + ['reason' => $reason]
        );
    }

    /**
     * @return array<string, int|string|bool|null> `getRemoteAddress()` answers false when it cannot
     *         read one, and that is recorded as-is rather than smoothed into an empty string.
     */
    private function context(int $customerId): array
    {
        return [
            'customer_id' => $customerId,
            'acting_user_id' => $this->userContext->getUserId(),
            'acting_user_type' => $this->userContext->getUserType(),
            'ip' => $this->remoteAddress->getRemoteAddress(),
        ];
    }
}
