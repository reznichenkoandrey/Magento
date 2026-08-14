<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Test\Unit\Model;

use Magento\Framework\App\Request\Http as HttpRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\FpcInspector\Model\Config;
use Scr1be\FpcInspector\Model\RecordBuilder;
use Scr1be\FpcInspector\Model\RecordingGate;
use Scr1be\FpcInspector\Model\RequestScope;

class RecordingGateTest extends TestCase
{
    private const URI = '/gear/bags.html';

    private Config&MockObject $config;
    private RequestScope $scope;
    private HttpRequest&MockObject $request;
    private RecordingGate $gate;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->scope = new RequestScope();
        $this->request = $this->createMock(HttpRequest::class);
        $this->request->method('getUriString')->willReturn(self::URI);

        $this->gate = new RecordingGate($this->config, $this->scope, $this->request);
    }

    public function testAnEnabledChannelOnAMatchingUriIsAllowed(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isVaryChannelEnabled')->willReturn(true);
        $this->config->method('matchesUri')->with(self::URI)->willReturn(true);

        $this->assertTrue($this->gate->allows(RecordBuilder::CHANNEL_VARY));
    }

    public function testTheMasterSwitchIsCheckedBeforeAnythingElse(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->config->expects($this->never())->method('isVaryChannelEnabled');
        $this->config->expects($this->never())->method('matchesUri');

        $this->assertFalse($this->gate->allows(RecordBuilder::CHANNEL_VARY));
    }

    public function testEachChannelIsGatedSeparately(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isVaryChannelEnabled')->willReturn(false);
        $this->config->method('isNoCacheChannelEnabled')->willReturn(true);
        $this->config->method('matchesUri')->willReturn(true);

        $this->assertFalse($this->gate->allows(RecordBuilder::CHANNEL_VARY));
        $this->assertTrue($this->gate->allows(RecordBuilder::CHANNEL_NO_CACHE));
    }

    public function testAUriOutsideTheFilterIsRefused(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('isNoCacheChannelEnabled')->willReturn(true);
        $this->config->method('matchesUri')->with(self::URI)->willReturn(false);

        $this->assertFalse($this->gate->allows(RecordBuilder::CHANNEL_NO_CACHE));
    }

    public function testRecordingInProgressShortCircuitsBeforeAnyConfigIsRead(): void
    {
        // The re-entrancy case: the no-cache hook asks the context for a vary string while
        // assembling its record, and that question must not become a record of its own.
        $this->scope->beginRecording();

        $this->config->expects($this->never())->method('isEnabled');

        $this->assertFalse($this->gate->allows(RecordBuilder::CHANNEL_VARY));
    }

    public function testAnUnknownChannelIsRefused(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $this->assertFalse($this->gate->allows('something-else'));
    }
}
