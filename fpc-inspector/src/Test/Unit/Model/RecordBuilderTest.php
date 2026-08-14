<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Test\Unit\Model;

use Magento\Framework\App\Http\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\FpcInspector\Model\Config;
use Scr1be\FpcInspector\Model\Inspector\CacheVerdict;
use Scr1be\FpcInspector\Model\Inspector\StackTrace;
use Scr1be\FpcInspector\Model\Inspector\VaryBreakdown;
use Scr1be\FpcInspector\Model\RecordBuilder;
use Scr1be\FpcInspector\Model\RequestScope;

class RecordBuilderTest extends TestCase
{
    private const URI = '/gear/bags.html';

    private HttpRequest&MockObject $request;
    private VaryBreakdown&MockObject $breakdown;
    private CacheVerdict&MockObject $verdict;
    private StackTrace&MockObject $stackTrace;
    private RecordBuilder $builder;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->request->method('getUriString')->willReturn(self::URI);
        $this->request->method('getMethod')->willReturn('GET');

        $config = $this->createMock(Config::class);
        $config->method('getStackDepth')->willReturn(12);

        $this->breakdown = $this->createMock(VaryBreakdown::class);
        $this->breakdown->method('explain')->willReturn(['contributors' => [], 'inert' => []]);

        // Left unstubbed on purpose: only the test that asserts on the verdict configures it, so no
        // earlier return-value matcher can shadow that expectation.
        $this->verdict = $this->createMock(CacheVerdict::class);

        $this->stackTrace = $this->createMock(StackTrace::class);
        $this->stackTrace->method('capture')->willReturn(['Foo::bar (file.php:1)']);

        $this->builder = new RecordBuilder(
            $this->request,
            $config,
            new RequestScope(),
            $this->breakdown,
            $this->verdict,
            $this->stackTrace
        );
    }

    public function testAFirstVisitIsAMismatchAndWritesTheCookie(): void
    {
        $record = $this->build('abc123', null);

        // Core compares the two strictly, and null is not 'abc123' — this is the mismatch that
        // makes HttpPlugin stamp no-cache and send the cookie on a shopper's first varied page.
        $this->assertFalse($record['vary_matches_cookie']);
        $this->assertSame(RecordBuilder::COOKIE_ACTION_SET, $record['cookie_action']);
    }

    public function testAStaleCookieIsReportedAsAMismatch(): void
    {
        $record = $this->build('fresh', 'stale');

        $this->assertSame('fresh', $record['vary']);
        $this->assertSame('stale', $record['vary_cookie']);
        $this->assertFalse($record['vary_matches_cookie']);
        $this->assertSame(RecordBuilder::COOKIE_ACTION_SET, $record['cookie_action']);
    }

    public function testASettledSessionAgreesOnEverything(): void
    {
        $record = $this->build('abc123', 'abc123');

        $this->assertTrue($record['vary_matches_cookie']);
        $this->assertSame(RecordBuilder::COOKIE_ACTION_SET, $record['cookie_action']);
    }

    public function testAnEmptyCookieIsStillAMismatch(): void
    {
        // sendVary() treats '' as nothing to clear, but HttpPlugin's strict !== calls it a
        // mismatch all the same. Both facts belong on the record.
        $record = $this->build('abc123', '');

        $this->assertFalse($record['vary_matches_cookie']);
    }

    public function testAContextThatWentEmptyClearsTheCookieItStillCarries(): void
    {
        $record = $this->build(null, 'stale');

        $this->assertFalse($record['vary_matches_cookie']);
        $this->assertSame(RecordBuilder::COOKIE_ACTION_DELETE, $record['cookie_action']);
    }

    public function testAnUnvariedPageAgreesWithTheAbsenceOfACookie(): void
    {
        $record = $this->build(null, null);

        $this->assertNull($record['vary']);
        $this->assertTrue($record['vary_matches_cookie']);
        $this->assertSame(RecordBuilder::COOKIE_ACTION_NONE, $record['cookie_action']);
    }

    public function testStoreAndCurrencyAreReadFromTheContextThatBuiltTheKey(): void
    {
        $context = $this->context();
        $context->method('getValue')->willReturnMap([
            [StoreManagerInterface::CONTEXT_STORE, 'french'],
            [Context::CONTEXT_CURRENCY, 'EUR'],
        ]);

        $record = $this->builder->build(RecordBuilder::CHANNEL_VARY, $context, 'abc', null);

        $this->assertSame('french', $record['store']);
        $this->assertSame('EUR', $record['currency']);
    }

    public function testANonScalarContextValueIsReportedAsAbsentRatherThanCoerced(): void
    {
        $context = $this->context();
        $context->method('getValue')->willReturn(['unexpected' => 'shape']);

        $record = $this->builder->build(RecordBuilder::CHANNEL_VARY, $context, 'abc', null);

        $this->assertNull($record['store']);
        $this->assertNull($record['currency']);
    }

    public function testTheRecordCarriesTheRequestUriMethodChannelAndCorrelationFields(): void
    {
        $record = $this->build('abc', null);

        $this->assertSame(self::URI, $record['uri']);
        $this->assertSame('GET', $record['method']);
        $this->assertSame(RecordBuilder::CHANNEL_VARY, $record['channel']);
        $this->assertSame(1, $record['seq']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $record['request_id']);
        $this->assertSame(['Foo::bar (file.php:1)'], $record['stack']);
    }

    public function testTheResponseIsHandedToTheVerdictUntouched(): void
    {
        $response = $this->createMock(HttpResponse::class);

        $this->verdict->expects($this->once())->method('evaluate')->with($response)->willReturn(['verdict' => 'yes']);

        $record = $this->builder->build(RecordBuilder::CHANNEL_NO_CACHE, $this->context(), 'abc', $response);

        $this->assertSame(['verdict' => 'yes'], $record['will_cache']);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(?string $varyString, ?string $cookie): array
    {
        $this->request->method('get')->willReturn($cookie);

        return $this->builder->build(RecordBuilder::CHANNEL_VARY, $this->context(), $varyString, null);
    }

    private function context(): Context&MockObject
    {
        return $this->createMock(Context::class);
    }
}
