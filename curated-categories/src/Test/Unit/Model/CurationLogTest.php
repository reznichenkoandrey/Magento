<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scr1be\CuratedCategories\Model\Config;
use Scr1be\CuratedCategories\Model\CurationLog;
use Scr1be\CuratedCategories\Model\CurationResult;
use Scr1be\CuratedCategories\Model\CurationTarget;

class CurationLogTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private Config&MockObject $config;
    private CurationLog $log;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->log = new CurationLog($this->logger, $this->config);
    }

    /**
     * The ids, not just the counts. Counts say a change happened; ids answer "why did this product
     * leave the bestsellers page", which is the only question anyone ever asks of this log.
     */
    public function testARunIsLoggedWithTheAffectedIds(): void
    {
        $this->config->method('isRunLoggingEnabled')->willReturn(true);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('bestsellers: reconciled category 9'),
                [
                    'added' => [1],
                    'removed' => [2, 3],
                    'retained_by_floor' => [4],
                ]
            );

        $this->log->logResult($this->curationResult([1], [2, 3], [5], [4], false));
    }

    public function testADryRunSaysSo(): void
    {
        $this->config->method('isRunLoggingEnabled')->willReturn(true);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('dry run on'), $this->anything());

        $this->log->logResult($this->curationResult([], [], [], [], true));
    }

    public function testRunLoggingCanBeSwitchedOff(): void
    {
        $this->config->method('isRunLoggingEnabled')->willReturn(false);
        $this->logger->expects($this->never())->method('info');

        $this->log->logResult($this->curationResult([1], [], [], [], false));
    }

    /**
     * A guard that fired silently is indistinguishable from a source that had nothing to do, so a
     * refusal is a warning whatever the run-logging setting says.
     */
    public function testARefusalIsAlwaysLogged(): void
    {
        $this->config->method('isRunLoggingEnabled')->willReturn(false);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Refused: source returned no products'));
        $this->logger->expects($this->never())->method('info');

        $this->log->logResult(
            CurationResult::refused(
                new CurationTarget(9, 4, 'bestsellers'),
                'source returned no products'
            )
        );
    }

    public function testSkipsAndFailuresAreWarningsAndErrors(): void
    {
        $this->logger->expects($this->once())->method('warning')->with('Skipped coming_soon: not registered.');
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('bestsellers failed: boom'), $this->anything());

        $this->log->logSkipped('coming_soon', 'not registered');
        $this->log->logFailure('bestsellers', new \RuntimeException('boom'));
    }

    /**
     * @param int[] $added
     * @param int[] $removed
     * @param int[] $unchanged
     * @param int[] $retained
     */
    private function curationResult(
        array $added,
        array $removed,
        array $unchanged,
        array $retained,
        bool $dryRun
    ): CurationResult {
        return CurationResult::of(
            new CurationTarget(9, 4, 'bestsellers'),
            $added,
            $removed,
            $unchanged,
            $retained,
            $dryRun
        );
    }
}
