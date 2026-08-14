<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Console;

use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Api\CurationSourceInterface;
use Scr1be\CuratedCategories\Console\Command\ReconcileCommand;
use Scr1be\CuratedCategories\Model\CurationLog;
use Scr1be\CuratedCategories\Model\CurationResult;
use Scr1be\CuratedCategories\Model\CurationTarget;
use Scr1be\CuratedCategories\Model\SourcePool;
use Scr1be\CuratedCategories\Model\SourceRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ReconcileCommandTest extends TestCase
{
    private SourceRunner&MockObject $runner;
    private CurationLog&MockObject $log;
    private State&MockObject $appState;

    protected function setUp(): void
    {
        $this->runner = $this->createMock(SourceRunner::class);
        $this->log = $this->createMock(CurationLog::class);
        $this->appState = $this->createMock(State::class);
    }

    public function testWithNoArgumentRunsEveryEnabledSource(): void
    {
        $pool = new SourcePool([
            'bestsellers' => $this->source(true),
            'new_arrivals' => $this->source(false),
        ]);

        $this->runner->expects($this->once())
            ->method('run')
            ->with($this->anything(), false)
            ->willReturn($this->curationResult('bestsellers', [11, 12], [13]));

        $tester = $this->tester($pool);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('bestsellers', $tester->getDisplay());
    }

    /**
     * Someone who typed the code meant that source; refusing on the grounds of a setting they can
     * see would only cost them a round trip through the admin.
     */
    public function testANamedSourceRunsEvenWhenItIsSwitchedOff(): void
    {
        $pool = new SourcePool(['bestsellers' => $this->source(false)]);

        $this->runner->expects($this->once())->method('run')->willReturn($this->curationResult('bestsellers'));

        $this->assertSame(Command::SUCCESS, $this->tester($pool)->execute(['source' => 'bestsellers']));
    }

    public function testAnUnknownSourceIsAFailureAndRunsNothing(): void
    {
        $pool = new SourcePool(['bestsellers' => $this->source(true)]);

        $this->runner->expects($this->never())->method('run');

        $tester = $this->tester($pool);

        $this->assertSame(Command::FAILURE, $tester->execute(['source' => 'nope']));
        $this->assertStringContainsString('bestsellers', $tester->getDisplay());
    }

    public function testDryRunIsPassedThroughAndAnnounced(): void
    {
        $pool = new SourcePool(['bestsellers' => $this->source(true)]);

        $this->runner->expects($this->once())
            ->method('run')
            ->with($this->anything(), true)
            ->willReturn($this->curationResult('bestsellers'));

        $tester = $this->tester($pool);
        $tester->execute(['--dry-run' => true]);

        $this->assertStringContainsString('nothing will be written', $tester->getDisplay());
    }

    /**
     * Refusals are outcomes, not faults: the guards did their job, so the reason is printed and the
     * exit code stays zero. A deployment pipeline should only fail on something that actually broke.
     */
    public function testARefusalPrintsItsReasonAndStillSucceeds(): void
    {
        $pool = new SourcePool(['bestsellers' => $this->source(true)]);
        $refusal = CurationResult::refused(new CurationTarget(9, 4, 'bestsellers'), 'source returned no products');

        $this->runner->method('run')->willReturn($refusal);

        $tester = $this->tester($pool);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('no products', $tester->getDisplay());
    }

    public function testAThrowingSourceIsLoggedAndExitsNonZero(): void
    {
        $pool = new SourcePool(['bestsellers' => $this->source(true)]);

        $this->runner->method('run')->willThrowException(new \RuntimeException('no such table'));
        $this->log->expects($this->once())->method('logFailure');

        $tester = $this->tester($pool);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('no such table', $tester->getDisplay());
    }

    public function testSaysSoWhenNothingIsEnabled(): void
    {
        $tester = $this->tester(new SourcePool(['bestsellers' => $this->source(false)]));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('No enabled', $tester->getDisplay());
    }

    /**
     * The area may already be set by whatever built the object graph; the command has to survive
     * that rather than die on its first line.
     */
    public function testSurvivesAnAreaCodeThatIsAlreadySet(): void
    {
        $this->appState->method('setAreaCode')
            ->willThrowException(new LocalizedException(__('Area code is already set')));

        $pool = new SourcePool(['bestsellers' => $this->source(true)]);
        $this->runner->method('run')->willReturn($this->curationResult('bestsellers'));

        $this->assertSame(Command::SUCCESS, $this->tester($pool)->execute([]));
    }

    public function testVerboseIdsPrintTheAffectedProducts(): void
    {
        $pool = new SourcePool(['bestsellers' => $this->source(true)]);
        $this->runner->method('run')->willReturn($this->curationResult('bestsellers', [11, 12], [13]));

        $tester = $this->tester($pool);
        $tester->execute(['--verbose-ids' => true]);

        $this->assertStringContainsString('11, 12', $tester->getDisplay());
    }

    private function tester(SourcePool $pool): CommandTester
    {
        return new CommandTester(new ReconcileCommand($pool, $this->runner, $this->log, $this->appState));
    }

    private function source(bool $enabled): CurationSourceInterface
    {
        $source = $this->createMock(CurationSourceInterface::class);
        $source->method('isEnabled')->willReturn($enabled);

        return $source;
    }

    /**
     * @param int[] $added
     * @param int[] $removed
     */
    private function curationResult(string $code, array $added = [], array $removed = []): CurationResult
    {
        return CurationResult::of(new CurationTarget(9, 4, $code), $added, $removed, [], [], false);
    }
}
