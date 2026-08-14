<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Test\Unit\Plugin;

use Magento\Framework\App\Http\Context;
use Magento\Framework\App\Response\Http as HttpResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\FpcInspector\Model\RecordBuilder;
use Scr1be\FpcInspector\Model\Recorder;
use Scr1be\FpcInspector\Model\RecordingGate;
use Scr1be\FpcInspector\Model\RequestScope;
use Scr1be\FpcInspector\Plugin\LogNoCacheHeaders;

class LogNoCacheHeadersTest extends TestCase
{
    private RecordingGate&MockObject $gate;
    private RequestScope $scope;
    private RecordBuilder&MockObject $builder;
    private Recorder&MockObject $recorder;
    private Context&MockObject $context;
    private HttpResponse&MockObject $response;
    private LogNoCacheHeaders $plugin;

    protected function setUp(): void
    {
        $this->gate = $this->createMock(RecordingGate::class);
        $this->scope = new RequestScope();
        $this->builder = $this->createMock(RecordBuilder::class);
        $this->recorder = $this->createMock(Recorder::class);
        $this->context = $this->createMock(Context::class);
        $this->response = $this->createMock(HttpResponse::class);

        $this->plugin = new LogNoCacheHeaders(
            $this->gate,
            $this->scope,
            $this->builder,
            $this->recorder,
            $this->context
        );
    }

    public function testAShutGateCostsNothingAtAll(): void
    {
        $this->gate->method('allows')->willReturn(false);

        $this->context->expects($this->never())->method('getVaryString');
        $this->builder->expects($this->never())->method('build');
        $this->recorder->expects($this->never())->method('record');

        $this->plugin->beforeSetNoCacheHeaders($this->response);
    }

    public function testTheContextIsQueriedOnlyWithTheReEntrancyGuardUp(): void
    {
        // This is the seam that matters: asking the context for a vary string re-enters the
        // interceptor the sibling hook lives on, so the flag has to be up before the question is
        // asked, not merely before the record is written.
        $this->allow();
        $flagWhileAsking = null;
        $this->context->method('getVaryString')->willReturnCallback(function () use (&$flagWhileAsking): string {
            $flagWhileAsking = $this->scope->isRecording();

            return 'abc123';
        });
        $this->builder->method('build')->willReturn($this->record('public, max-age=86400', 'Kernel::process'));

        $this->plugin->beforeSetNoCacheHeaders($this->response);

        $this->assertTrue($flagWhileAsking);
        $this->assertFalse($this->scope->isRecording());
    }

    public function testTheRecordIsBuiltFromTheResponseBeingStamped(): void
    {
        $this->allow();
        $this->context->method('getVaryString')->willReturn('abc123');

        $this->builder->expects($this->once())
            ->method('build')
            ->with(RecordBuilder::CHANNEL_NO_CACHE, $this->context, 'abc123', $this->response)
            ->willReturn($this->record('public, max-age=86400', 'Kernel::process'));

        $this->recorder->expects($this->once())->method('record');

        $this->plugin->beforeSetNoCacheHeaders($this->response);
    }

    public function testANullVaryStringIsPassedThroughAsNull(): void
    {
        $this->allow();
        $this->context->method('getVaryString')->willReturn(null);

        $this->builder->expects($this->once())
            ->method('build')
            ->with(RecordBuilder::CHANNEL_NO_CACHE, $this->context, null, $this->response)
            ->willReturn($this->record(null, 'FrontController::processRequest'));

        $this->plugin->beforeSetNoCacheHeaders($this->response);
    }

    public function testEachCallSiteEarnsItsOwnLine(): void
    {
        // An ordinary cacheable page reaches this method from the front controller and again from
        // the cache kernel, and the two records mean opposite things.
        $this->allow();
        $this->context->method('getVaryString')->willReturn('abc123');
        $this->builder->method('build')->willReturnOnConsecutiveCalls(
            $this->record(null, 'Magento\\Framework\\App\\FrontController::processRequest'),
            $this->record('public, max-age=86400', 'Magento\\Framework\\App\\PageCache\\Kernel::process')
        );

        $this->recorder->expects($this->exactly(2))->method('record');

        $this->plugin->beforeSetNoCacheHeaders($this->response);
        $this->plugin->beforeSetNoCacheHeaders($this->response);
    }

    public function testTheSameCallSiteReStampingTheSameHeaderIsWrittenOnce(): void
    {
        $this->allow();
        $this->context->method('getVaryString')->willReturn('abc123');
        $this->builder->method('build')->willReturn($this->record(null, 'FrontController::processRequest'));

        $this->recorder->expects($this->once())->method('record');

        $this->plugin->beforeSetNoCacheHeaders($this->response);
        $this->plugin->beforeSetNoCacheHeaders($this->response);
    }

    public function testTheSameCallerReplacingADifferentHeaderEarnsASecondLine(): void
    {
        $this->allow();
        $this->context->method('getVaryString')->willReturn('abc123');
        $this->builder->method('build')->willReturnOnConsecutiveCalls(
            $this->record(null, 'HttpPlugin::beforeSendResponse'),
            $this->record('public, max-age=86400', 'HttpPlugin::beforeSendResponse')
        );

        $this->recorder->expects($this->exactly(2))->method('record');

        $this->plugin->beforeSetNoCacheHeaders($this->response);
        $this->plugin->beforeSetNoCacheHeaders($this->response);
    }

    public function testAFailureIsReportedAndTheGuardIsReleased(): void
    {
        $this->allow();
        $this->context->method('getVaryString')->willThrowException(new \RuntimeException('boom'));

        $this->recorder->expects($this->once())->method('failed');

        $this->plugin->beforeSetNoCacheHeaders($this->response);

        $this->assertFalse($this->scope->isRecording());
    }

    private function allow(): void
    {
        $this->gate->method('allows')->with(RecordBuilder::CHANNEL_NO_CACHE)->willReturn(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(?string $cacheControl, string $topFrame): array
    {
        return [
            'will_cache' => ['cache_control' => $cacheControl],
            'stack' => [$topFrame],
        ];
    }
}
