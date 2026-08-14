<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\FpcInspector\Model\RequestScope;

class RequestScopeTest extends TestCase
{
    private RequestScope $scope;

    protected function setUp(): void
    {
        $this->scope = new RequestScope();
    }

    public function testTheRequestIdIsStableWithinARequest(): void
    {
        $first = $this->scope->getRequestId();

        $this->assertSame($first, $this->scope->getRequestId());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $first);
    }

    public function testTheSequenceCountsFromOne(): void
    {
        $this->assertSame(1, $this->scope->nextSequence());
        $this->assertSame(2, $this->scope->nextSequence());
    }

    public function testAFingerprintIsRecordedOnceAndThenSuppressed(): void
    {
        $this->assertTrue($this->scope->isFirstSighting('vary|abc|Foo::bar'));
        $this->assertFalse($this->scope->isFirstSighting('vary|abc|Foo::bar'));
    }

    public function testTheSameValueFromADifferentCallSiteIsStillWorthALine(): void
    {
        $this->assertTrue($this->scope->isFirstSighting('vary|abc|Identifier::getValue'));
        $this->assertTrue($this->scope->isFirstSighting('vary|abc|IdentifierForSave::getValue'));
    }

    public function testAChangedValueFromAKnownCallSiteIsStillWorthALine(): void
    {
        // A vary string that changes mid-request is the bug this policy exists to surface.
        $this->assertTrue($this->scope->isFirstSighting('vary|abc|Identifier::getValue'));
        $this->assertTrue($this->scope->isFirstSighting('vary|def|Identifier::getValue'));
    }

    public function testRecordingIsOffUntilItIsEnteredAndOffAgainAfterwards(): void
    {
        $this->assertFalse($this->scope->isRecording());

        $this->scope->beginRecording();
        $this->assertTrue($this->scope->isRecording());

        $this->scope->endRecording();
        $this->assertFalse($this->scope->isRecording());
    }

    public function testResettingClearsEverythingARequestAccumulated(): void
    {
        $before = $this->scope->getRequestId();
        $this->scope->nextSequence();
        $this->scope->isFirstSighting('vary|abc|Foo::bar');
        $this->scope->beginRecording();

        $this->scope->_resetState();

        $this->assertFalse($this->scope->isRecording());
        $this->assertSame(1, $this->scope->nextSequence());
        $this->assertTrue($this->scope->isFirstSighting('vary|abc|Foo::bar'));
        $this->assertNotSame($before, $this->scope->getRequestId());
    }
}
