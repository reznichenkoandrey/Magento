<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Api\CurationEngineInterface;
use Scr1be\CuratedCategories\Api\CurationSourceInterface;
use Scr1be\CuratedCategories\Model\CurationLog;
use Scr1be\CuratedCategories\Model\CurationResult;
use Scr1be\CuratedCategories\Model\CurationTarget;
use Scr1be\CuratedCategories\Model\SourceRunner;

class SourceRunnerTest extends TestCase
{
    private CurationEngineInterface&MockObject $engine;
    private CurationLog&MockObject $log;
    private SourceRunner $runner;

    protected function setUp(): void
    {
        $this->engine = $this->createMock(CurationEngineInterface::class);
        $this->log = $this->createMock(CurationLog::class);
        $this->runner = new SourceRunner($this->engine, $this->log);
    }

    public function testHandsTheSourcesProductsToTheEngineAndLogsTheOutcome(): void
    {
        $target = new CurationTarget(9, 4, 'bestsellers');
        $result = CurationResult::of($target, [1], [], [], [], false);

        $source = $this->createMock(CurationSourceInterface::class);
        $source->method('getTarget')->willReturn($target);
        $source->method('getProductIds')->willReturn([1, 2, 3]);

        $this->engine->expects($this->once())
            ->method('reconcileAll')
            ->with($target, [1, 2, 3], false)
            ->willReturn($result);
        $this->log->expects($this->once())->method('logResult')->with($result);

        $this->assertSame($result, $this->runner->run($source));
    }

    public function testPropagatesTheDryRunFlag(): void
    {
        $target = new CurationTarget(9, 4, 'bestsellers');

        $source = $this->createMock(CurationSourceInterface::class);
        $source->method('getTarget')->willReturn($target);
        $source->method('getProductIds')->willReturn([]);

        $this->engine->expects($this->once())
            ->method('reconcileAll')
            ->with($target, [], true)
            ->willReturn(CurationResult::of($target, [], [], [], [], true));

        $this->assertTrue($this->runner->run($source, true)->isDryRun());
    }

    /**
     * An unconfigured source is modelled as a refused result rather than a null return, so every
     * path out of a run has the same type and the CLI's table has one shape.
     */
    public function testAnUnconfiguredSourceIsARefusalRatherThanAQuery(): void
    {
        $source = $this->createMock(CurationSourceInterface::class);
        $source->method('getCode')->willReturn('coming_soon');
        $source->method('getTarget')->willReturn(null);
        $source->expects($this->never())->method('getProductIds');

        $this->engine->expects($this->never())->method('reconcileAll');
        $this->log->expects($this->once())->method('logResult');

        $result = $this->runner->run($source);

        $this->assertTrue($result->isRefused());
        $this->assertSame('coming_soon', $result->getSourceCode());
        $this->assertSame(0, $result->getCategoryId());
    }

    /**
     * The runner deliberately does not catch: cron wants to log and continue, the CLI wants to exit
     * non-zero, and swallowing here would take that choice away from both.
     */
    public function testLetsAFailingSourceThrough(): void
    {
        $source = $this->createMock(CurationSourceInterface::class);
        $source->method('getTarget')->willReturn(new CurationTarget(9, 4, 'bestsellers'));
        $source->method('getProductIds')->willThrowException(new \RuntimeException('no such table'));

        $this->expectException(\RuntimeException::class);

        $this->runner->run($source);
    }
}
