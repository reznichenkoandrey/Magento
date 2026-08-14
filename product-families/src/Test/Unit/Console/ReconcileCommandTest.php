<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Console;

use Magento\Framework\App\State;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Api\Data\ReconcileResultInterface;
use Scr1be\ProductFamilies\Api\FamilyReconcilerInterface;
use Scr1be\ProductFamilies\Console\Command\ReconcileCommand;
use Scr1be\ProductFamilies\Model\FamilyDefinitionPool;
use Scr1be\ProductFamilies\Model\FamilyLog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ReconcileCommandTest extends TestCase
{
    private FamilyReconcilerInterface&MockObject $reconciler;
    private FamilyDefinitionPool&MockObject $definitionPool;
    private FamilyLog&MockObject $log;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->reconciler = $this->createMock(FamilyReconcilerInterface::class);
        $this->definitionPool = $this->createMock(FamilyDefinitionPool::class);
        $this->log = $this->createMock(FamilyLog::class);

        $this->definitionPool->method('getFamilyCodes')
            ->willReturn(['other_colors', 'other_sizes', 'similar']);

        $this->tester = new CommandTester(new ReconcileCommand(
            $this->reconciler,
            $this->definitionPool,
            $this->log,
            $this->createMock(State::class)
        ));
    }

    public function testNoArgumentRunsEveryFamily(): void
    {
        $ran = [];
        $this->reconciler->method('reconcile')->willReturnCallback(
            function (string $familyCode) use (&$ran): ReconcileResultInterface {
                $ran[] = $familyCode;

                return $this->reconcileResult($familyCode);
            }
        );

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $this->assertSame(['other_colors', 'other_sizes', 'similar'], $ran);
    }

    public function testASingleFamilyArgumentNarrowsTheRun(): void
    {
        $this->definitionPool->method('has')->willReturn(true);
        $this->reconciler->expects($this->once())
            ->method('reconcile')
            ->willReturn($this->reconcileResult('similar'));

        $this->assertSame(Command::SUCCESS, $this->tester->execute(['family' => 'similar']));
    }

    /**
     * A typo must not be indistinguishable from a family that had nothing to do — the run would
     * exit zero having done nothing at all.
     */
    public function testAnUnknownFamilyFailsAndNamesTheOnesThatExist(): void
    {
        $this->definitionPool->method('has')->willReturn(false);
        $this->reconciler->expects($this->never())->method('reconcile');

        $this->assertSame(Command::FAILURE, $this->tester->execute(['family' => 'colours']));
        $this->assertStringContainsString('other_colors, other_sizes, similar', $this->tester->getDisplay());
    }

    public function testTheDryRunFlagReachesTheReconciler(): void
    {
        $this->definitionPool->method('has')->willReturn(true);
        $this->reconciler->expects($this->once())
            ->method('reconcile')
            ->with('similar', true)
            ->willReturn($this->reconcileResult('similar', dryRun: true));

        $this->tester->execute(['family' => 'similar', '--dry-run' => true]);

        $this->assertStringContainsString('Dry run', $this->tester->getDisplay());
        $this->assertStringContainsString('planned', $this->tester->getDisplay());
    }

    /**
     * A refusal is the guards doing their job, not a failure. A pipeline that treats "the family is
     * switched off" as a broken build would be unusable.
     */
    public function testARefusedFamilyPrintsItsReasonAndStillExitsZero(): void
    {
        $this->definitionPool->method('has')->willReturn(true);
        $this->reconciler->method('reconcile')->willReturn(
            $this->reconcileResult('similar', refusalReason: 'family "similar" is switched off')
        );

        $this->assertSame(Command::SUCCESS, $this->tester->execute(['family' => 'similar']));
        $this->assertStringContainsString('switched off', $this->tester->getDisplay());
    }

    public function testAThrowingFamilyIsLoggedAndTheCommandFails(): void
    {
        $this->definitionPool->method('has')->willReturn(true);
        $this->reconciler->method('reconcile')->willThrowException(
            new \RuntimeException('attribute "colour" does not exist')
        );
        $this->log->expects($this->once())->method('logFailure');

        $this->assertSame(Command::FAILURE, $this->tester->execute(['family' => 'other_colors']));
        $this->assertStringContainsString('attribute "colour" does not exist', $this->tester->getDisplay());
    }

    public function testOneThrowingFamilyDoesNotStopTheRestOfTheRun(): void
    {
        $ran = [];
        $this->reconciler->method('reconcile')->willReturnCallback(
            function (string $familyCode) use (&$ran): ReconcileResultInterface {
                $ran[] = $familyCode;
                if ($familyCode === 'other_colors') {
                    throw new \RuntimeException('boom');
                }

                return $this->reconcileResult($familyCode);
            }
        );

        $this->assertSame(Command::FAILURE, $this->tester->execute([]));
        $this->assertSame(['other_colors', 'other_sizes', 'similar'], $ran);
    }

    private function reconcileResult(
        string $familyCode,
        bool $dryRun = false,
        ?string $refusalReason = null
    ): ReconcileResultInterface&MockObject {
        $result = $this->createMock(ReconcileResultInterface::class);
        $result->method('getFamilyCode')->willReturn($familyCode);
        $result->method('isDryRun')->willReturn($dryRun);
        $result->method('isRefused')->willReturn($refusalReason !== null);
        $result->method('getRefusalReason')->willReturn($refusalReason);
        $result->method('getAffectedProductIds')->willReturn([]);

        return $result;
    }
}
