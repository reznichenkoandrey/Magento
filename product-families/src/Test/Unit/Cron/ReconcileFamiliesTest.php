<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Cron;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Api\Data\ReconcileResultInterface;
use Scr1be\ProductFamilies\Api\FamilyReconcilerInterface;
use Scr1be\ProductFamilies\Cron\ReconcileFamilies;
use Scr1be\ProductFamilies\Model\Config;
use Scr1be\ProductFamilies\Model\FamilyDefinitionPool;
use Scr1be\ProductFamilies\Model\FamilyLog;

class ReconcileFamiliesTest extends TestCase
{
    private Config&MockObject $config;
    private FamilyDefinitionPool&MockObject $definitionPool;
    private FamilyReconcilerInterface&MockObject $reconciler;
    private FamilyLog&MockObject $log;
    private ReconcileFamilies $cron;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->definitionPool = $this->createMock(FamilyDefinitionPool::class);
        $this->reconciler = $this->createMock(FamilyReconcilerInterface::class);
        $this->log = $this->createMock(FamilyLog::class);

        $this->cron = new ReconcileFamilies(
            $this->config,
            $this->definitionPool,
            $this->reconciler,
            $this->log
        );
    }

    public function testTheMasterSwitchStopsTheScheduleBeforeItAsksTheFamilies(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->definitionPool->expects($this->never())->method('getFamilyCodes');
        $this->reconciler->expects($this->never())->method('reconcile');

        $this->cron->execute();
    }

    public function testTheScheduleSwitchIsIndependentOfTheModuleSwitch(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isCronEnabled')->willReturn(false);
        $this->reconciler->expects($this->never())->method('reconcile');

        $this->cron->execute();
    }

    /**
     * The gate that makes the first night on a live catalogue safe: the whole plan is computed and
     * logged, and nothing is written.
     */
    public function testTheDryRunGateIsPassedThroughToEveryFamily(): void
    {
        $this->givenScheduleOn(dryRun: true);
        $this->definitionPool->method('getFamilyCodes')->willReturn(['other_colors', 'similar']);

        $seen = [];
        $this->reconciler->method('reconcile')->willReturnCallback(
            function (string $familyCode, bool $dryRun) use (&$seen): ReconcileResultInterface {
                $seen[$familyCode] = $dryRun;

                return $this->createMock(ReconcileResultInterface::class);
            }
        );

        $this->cron->execute();

        $this->assertSame(['other_colors' => true, 'similar' => true], $seen);
    }

    /**
     * A cron group runs to completion or not at all, and "the colour family had a deleted attribute
     * so nobody got sizes either" is not a trade worth making.
     */
    public function testOneFailingFamilyDoesNotStopTheNextOne(): void
    {
        $this->givenScheduleOn();
        $this->definitionPool->method('getFamilyCodes')->willReturn(['other_colors', 'other_sizes']);

        $attempted = [];
        $this->reconciler->method('reconcile')->willReturnCallback(
            function (string $familyCode) use (&$attempted): ReconcileResultInterface {
                $attempted[] = $familyCode;
                if ($familyCode === 'other_colors') {
                    throw new \RuntimeException('attribute "colour" does not exist');
                }

                return $this->createMock(ReconcileResultInterface::class);
            }
        );

        $this->log->expects($this->once())->method('logFailure')->with('other_colors');
        $this->log->expects($this->once())->method('logResult');

        $this->cron->execute();

        $this->assertSame(['other_colors', 'other_sizes'], $attempted);
    }

    private function givenScheduleOn(bool $dryRun = false): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isCronEnabled')->willReturn(true);
        $this->config->method('isCronDryRun')->willReturn($dryRun);
    }
}
