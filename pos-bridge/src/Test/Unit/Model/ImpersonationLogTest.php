<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Test\Unit\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\PosBridge\Model\ImpersonationLog;

/**
 * The audit trail is the only artefact of this module that outlives the request, so the fields it
 * carries are a contract with whoever reads the file six months later.
 */
class ImpersonationLogTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private UserContextInterface&MockObject $userContext;
    private RemoteAddress&MockObject $remoteAddress;
    private ImpersonationLog $log;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->userContext = $this->createMock(UserContextInterface::class);
        $this->remoteAddress = $this->createMock(RemoteAddress::class);

        $this->userContext->method('getUserId')->willReturn(4);
        $this->userContext->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_INTEGRATION);
        $this->remoteAddress->method('getRemoteAddress')->willReturn('203.0.113.9');

        $this->log = new ImpersonationLog($this->logger, $this->userContext, $this->remoteAddress);
    }

    public function testAnIssuedTokenRecordsWhoActedAsWhomFromWhere(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->anything(),
                [
                    'customer_id' => 42,
                    'acting_user_id' => 4,
                    'acting_user_type' => UserContextInterface::USER_TYPE_INTEGRATION,
                    'ip' => '203.0.113.9',
                    'expires_at' => '2026-08-13T21:30:00+00:00',
                ]
            );

        $this->log->issued(42, '2026-08-13T21:30:00+00:00');
    }

    /**
     * Refusals are recorded at warning level and carry the reason. A run of them is the shape a
     * stolen terminal credential makes, and it is invisible if only successes are written down.
     */
    public function testARefusalRecordsItsReason(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->anything(),
                $this->callback(static function (array $context): bool {
                    return $context['reason'] === 'account locked'
                        && $context['customer_id'] === 42
                        && $context['acting_user_id'] === 4;
                })
            );

        $this->log->refused(42, 'account locked');
    }

    /**
     * Outside a web API request there is no authenticated consumer. The line is still written — a
     * trail with an unknown actor is worth more than no trail.
     */
    public function testAnUnauthenticatedContextStillProducesALine(): void
    {
        $userContext = $this->createMock(UserContextInterface::class);
        $userContext->method('getUserId')->willReturn(null);
        $userContext->method('getUserType')->willReturn(null);

        $remoteAddress = $this->createMock(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturn(false);

        $log = new ImpersonationLog($this->logger, $userContext, $remoteAddress);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->anything(),
                $this->callback(static function (array $context): bool {
                    return $context['acting_user_id'] === null
                        && $context['acting_user_type'] === null
                        && $context['ip'] === false;
                })
            );

        $log->refused(42, 'impersonation disabled');
    }
}
