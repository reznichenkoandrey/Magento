<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Test\Unit\Model\Inspector;

use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\PageCache\NotCacheableInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\HttpInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\FpcInspector\Model\Inspector\CacheVerdict;

class CacheVerdictTest extends TestCase
{
    private const PUBLIC_HEADER = 'public, max-age=86400, s-maxage=86400';
    private const NO_CACHE_HEADER = 'no-store, no-cache, must-revalidate, max-age=0';

    private HttpRequest&MockObject $request;
    private PageCacheConfig&MockObject $pageCacheConfig;
    private CacheVerdict $verdict;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->request->method('isGet')->willReturn(true);
        $this->request->method('isHead')->willReturn(false);

        $this->pageCacheConfig = $this->createMock(PageCacheConfig::class);
        $this->pageCacheConfig->method('isEnabled')->willReturn(true);
        $this->pageCacheConfig->method('getType')->willReturn(PageCacheConfig::BUILT_IN);
        $this->pageCacheConfig->method('getTtl')->willReturn('86400');

        $this->verdict = new CacheVerdict($this->request, $this->pageCacheConfig);
    }

    public function testAPublicResponseToACacheableGetIsAYes(): void
    {
        $result = $this->verdict->evaluate($this->response(self::PUBLIC_HEADER, 200));

        $this->assertSame(CacheVerdict::VERDICT_YES, $result['verdict']);
        $this->assertSame(86400, $result['s_maxage']);
        $this->assertSame(self::PUBLIC_HEADER, $result['cache_control']);
    }

    public function testNoResponseInScopeIsReportedAsUnknownRatherThanNo(): void
    {
        // "Unknown" and "no" are different answers, and a debugging tool that conflates them sends
        // the reader looking for a culprit that does not exist.
        $result = $this->verdict->evaluate(null);

        $this->assertSame(CacheVerdict::VERDICT_UNKNOWN, $result['verdict']);
        $this->assertNull($result['cache_control']);
    }

    public function testAResponseWithoutACacheControlHeaderIsANo(): void
    {
        $result = $this->verdict->evaluate($this->response(null, 200));

        $this->assertSame(CacheVerdict::VERDICT_NO, $result['verdict']);
        $this->assertStringContainsString('no Cache-Control', $result['reason']);
    }

    public function testAPrivateCacheControlIsANo(): void
    {
        $result = $this->verdict->evaluate($this->response('private, max-age=3600', 200));

        $this->assertSame(CacheVerdict::VERDICT_NO, $result['verdict']);
        $this->assertStringContainsString('public + s-maxage', $result['reason']);
    }

    public function testTheDefaultNoCacheHeadersAreANo(): void
    {
        $result = $this->verdict->evaluate($this->response(self::NO_CACHE_HEADER, 200));

        $this->assertSame(CacheVerdict::VERDICT_NO, $result['verdict']);
    }

    public function testAPublicResponseToAPostIsStillANo(): void
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('isGet')->willReturn(false);
        $request->method('isHead')->willReturn(false);

        $verdict = new CacheVerdict($request, $this->pageCacheConfig);
        $result = $verdict->evaluate($this->response(self::PUBLIC_HEADER, 200));

        $this->assertSame(CacheVerdict::VERDICT_NO, $result['verdict']);
        $this->assertStringContainsString('GET and HEAD', $result['reason']);
    }

    public function testAPublicResponseWithAnUnstorableStatusIsANo(): void
    {
        $result = $this->verdict->evaluate($this->response(self::PUBLIC_HEADER, 302));

        $this->assertSame(CacheVerdict::VERDICT_NO, $result['verdict']);
        $this->assertStringContainsString('200 or 404', $result['reason']);
    }

    public function testA404IsStorableJustLikeA200(): void
    {
        $result = $this->verdict->evaluate($this->response(self::PUBLIC_HEADER, 404));

        $this->assertSame(CacheVerdict::VERDICT_YES, $result['verdict']);
    }

    public function testANotCacheableResponseIsRefusedBeforeItsHeadersMatter(): void
    {
        $response = $this->createMockForIntersectionOfInterfaces([HttpInterface::class, NotCacheableInterface::class]);
        $response->method('getHeader')->willReturn($this->header(self::PUBLIC_HEADER));
        $response->method('getHttpResponseCode')->willReturn(200);

        $result = $this->verdict->evaluate($response);

        $this->assertSame(CacheVerdict::VERDICT_NO, $result['verdict']);
        $this->assertStringContainsString('NotCacheableInterface', $result['reason']);
    }

    public function testEveryVerdictNamesTheCachingApplicationInFront(): void
    {
        $result = $this->verdict->evaluate($this->response(self::PUBLIC_HEADER, 200));

        $this->assertSame('built-in', $result['backend']['application']);
        $this->assertTrue($result['backend']['cache_type_enabled']);
        $this->assertSame(86400, $result['backend']['configured_ttl']);
    }

    public function testVarnishIsNamedTooBecauseTheRulesLandElsewhere(): void
    {
        $pageCacheConfig = $this->createMock(PageCacheConfig::class);
        $pageCacheConfig->method('getType')->willReturn(PageCacheConfig::VARNISH);
        $pageCacheConfig->method('isEnabled')->willReturn(true);
        $pageCacheConfig->method('getTtl')->willReturn('120');

        $verdict = new CacheVerdict($this->request, $pageCacheConfig);
        $result = $verdict->evaluate($this->response(self::PUBLIC_HEADER, 200));

        $this->assertSame('varnish', $result['backend']['application']);
    }

    private function response(?string $cacheControl, int $status): HttpInterface&MockObject
    {
        $response = $this->createMock(HttpInterface::class);
        // getHeader() answers with false, not null, when the header is absent.
        $response->method('getHeader')->willReturn($cacheControl === null ? false : $this->header($cacheControl));
        $response->method('getHttpResponseCode')->willReturn($status);

        return $response;
    }

    private function header(string $value): HeaderInterface&MockObject
    {
        $header = $this->createMock(HeaderInterface::class);
        $header->method('getFieldValue')->willReturn($value);

        return $header;
    }
}
