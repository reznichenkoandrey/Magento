<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Cron;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Api\CurationSourceInterface;
use Scr1be\CuratedCategories\Cron\ReconcileSources;
use Scr1be\CuratedCategories\Model\CurationLog;
use Scr1be\CuratedCategories\Model\CurationResult;
use Scr1be\CuratedCategories\Model\CurationTarget;
use Scr1be\CuratedCategories\Model\SourcePool;
use Scr1be\CuratedCategories\Model\SourceRunner;

class ReconcileSourcesTest extends TestCase
{
    private SourceRunner&MockObject $runner;
    private CurationLog&MockObject $log;

    protected function setUp(): void
    {
        $this->runner = $this->createMock(SourceRunner::class);
        $this->log = $this->createMock(CurationLog::class);
    }

    public function testRunsEveryEnabledSourceInItsList(): void
    {
        $pool = new SourcePool([
            'new_arrivals' => $this->source(true),
            'coming_soon' => $this->source(true),
            'bestsellers' => $this->source(true),
        ]);

        $this->runner->expects($this->exactly(2))->method('run')->willReturn($this->curationResult());

        $this->job($pool, ['new_arrivals', 'coming_soon'])->execute();
    }

    public function testSkipsADisabledSourceWithoutLoggingNoise(): void
    {
        $pool = new SourcePool(['bestsellers' => $this->source(false)]);

        $this->runner->expects($this->never())->method('run');
        $this->log->expects($this->never())->method('logSkipped');

        $this->job($pool, ['bestsellers'])->execute();
    }

    /**
     * A code in the job's list with nothing behind it is a `di.xml` mistake, and the useful place to
     * say so is the module's own log rather than a fatal in the cron group.
     */
    public function testWarnsAboutACodeThatIsNotRegistered(): void
    {
        $this->log->expects($this->once())->method('logSkipped')->with('typo', $this->stringContains('registered'));
        $this->runner->expects($this->never())->method('run');

        $this->job(new SourcePool([]), ['typo'])->execute();
    }

    /**
     * Cron groups run their jobs in sequence in one process, so an uncaught exception here would
     * take the rest of the group with it — a much bigger problem than one stale feed.
     */
    public function testOneFailingSourceDoesNotStopTheNext(): void
    {
        $pool = new SourcePool([
            'new_arrivals' => $this->source(true),
            'coming_soon' => $this->source(true),
        ]);

        $runCount = 0;
        $this->runner->method('run')->willReturnCallback(
            function () use (&$runCount): CurationResult {
                $runCount++;

                if ($runCount === 1) {
                    throw new \RuntimeException('lock wait timeout');
                }

                return $this->curationResult();
            }
        );

        $this->log->expects($this->once())->method('logFailure')
            ->with('new_arrivals', $this->isInstanceOf(\RuntimeException::class));

        $this->job($pool, ['new_arrivals', 'coming_soon'])->execute();

        $this->assertSame(2, $runCount);
    }

    /**
     * @param string[] $codes
     */
    private function job(SourcePool $pool, array $codes): ReconcileSources
    {
        return new ReconcileSources($pool, $this->runner, $this->log, $codes);
    }

    private function source(bool $enabled): CurationSourceInterface
    {
        $source = $this->createMock(CurationSourceInterface::class);
        $source->method('isEnabled')->willReturn($enabled);

        return $source;
    }

    private function curationResult(): CurationResult
    {
        return CurationResult::of(new CurationTarget(9, 1, 'any'), [], [], [], [], false);
    }
}
