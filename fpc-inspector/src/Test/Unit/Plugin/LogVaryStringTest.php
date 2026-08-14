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
use Scr1be\FpcInspector\Plugin\LogVaryString;

/**
 * The seam between this module and Magento's interception contract. An after plugin owns the value
 * on its way back to the caller, so "hands it back untouched" is the property that has to hold
 * before any of the recording behaviour matters.
 */
class LogVaryStringTest extends TestCase
{
    private RecordingGate&MockObject $gate;
    private RequestScope $scope;
    private RecordBuilder&MockObject $builder;
    private Recorder&MockObject $recorder;
    private Context&MockObject $context;
    private LogVaryString $plugin;

    protected function setUp(): void
    {
        $this->gate = $this->createMock(RecordingGate::class);
        $this->scope = new RequestScope();
        $this->builder = $this->createMock(RecordBuilder::class);
        $this->recorder = $this->createMock(Recorder::class);
        $this->context = $this->createMock(Context::class);

        $this->plugin = new LogVaryString(
            $this->gate,
            $this->scope,
            $this->builder,
            $this->recorder,
            $this->createMock(HttpResponse::class)
        );
    }

    public function testTheVaryStringIsHandedBackUnchangedWhenRecording(): void
    {
        $this->allow();
        $this->builder->method('build')->willReturn($this->record('abc', 'Identifier::getValue'));

        $this->assertSame('abc123', $this->plugin->afterGetVaryString($this->context, 'abc123'));
    }

    public function testTheVaryStringIsHandedBackUnchangedWhenTheGateIsShut(): void
    {
        $this->gate->method('allows')->willReturn(false);
        $this->builder->expects($this->never())->method('build');
        $this->recorder->expects($this->never())->method('record');

        $this->assertSame('abc123', $this->plugin->afterGetVaryString($this->context, 'abc123'));
    }

    public function testANullVaryStringSurvivesTheRoundTrip(): void
    {
        // getVaryString() answers null for an empty context, and null is a meaningful answer:
        // the page is cached unvaried.
        $this->allow();
        $this->builder->method('build')->willReturn($this->record(null, 'Identifier::getValue'));

        $this->assertNull($this->plugin->afterGetVaryString($this->context, null));
    }

    public function testAnUnexpectedReturnTypeIsNeitherCoercedNorDropped(): void
    {
        // Nothing in core returns a non-string here, but this plugin must not be the thing that
        // rewrites another plugin's value on the way through.
        $this->allow();
        $this->builder->method('build')->willReturn($this->record(null, 'Identifier::getValue'));

        $this->assertSame(42, $this->plugin->afterGetVaryString($this->context, 42));
    }

    public function testTheFirstSightingOfACallSiteIsRecorded(): void
    {
        $this->allow();
        $this->builder->method('build')->willReturn($this->record('abc', 'Identifier::getValue'));

        $this->recorder->expects($this->once())->method('record');

        $this->plugin->afterGetVaryString($this->context, 'abc');
    }

    public function testTheSameAnswerToTheSameCallerIsNotWrittenTwice(): void
    {
        $this->allow();
        $this->builder->method('build')->willReturn($this->record('abc', 'Identifier::getValue'));

        $this->recorder->expects($this->once())->method('record');

        $this->plugin->afterGetVaryString($this->context, 'abc');
        $this->plugin->afterGetVaryString($this->context, 'abc');
    }

    public function testAValueThatChangesMidRequestEarnsASecondLine(): void
    {
        $this->allow();
        $this->builder->method('build')->willReturnOnConsecutiveCalls(
            $this->record('abc', 'Identifier::getValue'),
            $this->record('def', 'Identifier::getValue')
        );

        $this->recorder->expects($this->exactly(2))->method('record');

        $this->plugin->afterGetVaryString($this->context, 'abc');
        $this->plugin->afterGetVaryString($this->context, 'def');
    }

    public function testTheSameAnswerFromADifferentCallerEarnsItsOwnLine(): void
    {
        $this->allow();
        $this->builder->method('build')->willReturnOnConsecutiveCalls(
            $this->record('abc', 'Identifier::getValue'),
            $this->record('abc', 'IdentifierForSave::getValue')
        );

        $this->recorder->expects($this->exactly(2))->method('record');

        $this->plugin->afterGetVaryString($this->context, 'abc');
        $this->plugin->afterGetVaryString($this->context, 'abc');
    }

    public function testTheRecordingFlagIsUpWhileTheRecordIsAssembled(): void
    {
        $this->allow();
        $flagWhileBuilding = null;
        $this->builder->method('build')->willReturnCallback(function () use (&$flagWhileBuilding): array {
            $flagWhileBuilding = $this->scope->isRecording();

            return $this->record('abc', 'Identifier::getValue');
        });

        $this->plugin->afterGetVaryString($this->context, 'abc');

        $this->assertTrue($flagWhileBuilding);
        $this->assertFalse($this->scope->isRecording(), 'the flag must be cleared again afterwards');
    }

    public function testAFailureIsReportedAndTheValueStillComesBack(): void
    {
        $this->allow();
        $this->builder->method('build')->willThrowException(new \RuntimeException('boom'));

        $this->recorder->expects($this->once())->method('failed');

        $this->assertSame('abc', $this->plugin->afterGetVaryString($this->context, 'abc'));
        $this->assertFalse($this->scope->isRecording(), 'a throw must not leave the guard latched');
    }

    private function allow(): void
    {
        $this->gate->method('allows')->with(RecordBuilder::CHANNEL_VARY)->willReturn(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(?string $vary, string $topFrame): array
    {
        return ['vary' => $vary, 'stack' => [$topFrame]];
    }
}
