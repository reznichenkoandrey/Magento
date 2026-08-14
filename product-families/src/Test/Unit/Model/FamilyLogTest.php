<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Model;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\ProductFamilies\Api\Data\ReconcileResultInterface;
use Scr1be\ProductFamilies\Model\FamilyLog;

class FamilyLogTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private FamilyLog $log;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->log = new FamilyLog($this->logger);
    }

    /**
     * A family that quietly did nothing because it is half-configured looks exactly like a family
     * that had nothing to do — so a refusal is a warning, not an info line.
     */
    public function testARefusalIsAWarningCarryingItsReason(): void
    {
        $this->logger->expects($this->never())->method('info');
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('has no group attribute configured'));

        $this->log->logResult($this->reconcileResult(
            refused: true,
            refusalReason: 'family "other_colors" has no group attribute configured'
        ));
    }

    public function testASuccessfulRunLogsCountsInTheMessageAndIdsInTheContext(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('+2 ~1 -3 =4'),
                $this->callback(static fn (array $context): bool
                    => $context['affected_product_ids'] === [11, 12]
                    && $context['affected_truncated'] === 0)
            );

        $this->log->logResult($this->reconcileResult(affected: [11, 12]));
    }

    /**
     * A first run over a large catalogue affects every product in it, and a log line with fifty
     * thousand ids in it is a log line nobody reads. The count of what was dropped stays.
     */
    public function testTheIdListIsTruncatedAndSaysHowMuchItDropped(): void
    {
        $affected = range(1, 250);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $context): bool
                    => count($context['affected_product_ids']) === 200
                    && $context['affected_truncated'] === 50)
            );

        $this->log->logResult($this->reconcileResult(affected: $affected));
    }

    public function testADryRunSaysSoInTheLine(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('dry run over'), $this->anything());

        $this->log->logResult($this->reconcileResult(dryRun: true));
    }

    public function testAFailureCarriesTheThrowableForTheStackTrace(): void
    {
        $error = new \RuntimeException('attribute "colour" does not exist');

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('attribute "colour" does not exist'), ['exception' => $error]);

        $this->log->logFailure('other_colors', $error);
    }

    /**
     * @param int[] $affected
     */
    private function reconcileResult(
        bool $refused = false,
        ?string $refusalReason = null,
        bool $dryRun = false,
        array $affected = []
    ): ReconcileResultInterface&MockObject {
        $result = $this->createMock(ReconcileResultInterface::class);
        $result->method('getFamilyCode')->willReturn('other_colors');
        $result->method('isRefused')->willReturn($refused);
        $result->method('getRefusalReason')->willReturn($refusalReason);
        $result->method('isDryRun')->willReturn($dryRun);
        $result->method('getFamilyCount')->willReturn(9);
        $result->method('getMemberCount')->willReturn(30);
        $result->method('getInsertedCount')->willReturn(2);
        $result->method('getUpdatedCount')->willReturn(1);
        $result->method('getDeletedCount')->willReturn(3);
        $result->method('getUnchangedCount')->willReturn(4);
        $result->method('getAffectedProductIds')->willReturn($affected);

        return $result;
    }
}
