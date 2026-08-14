<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Model\Inspector;

use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\PageCache\NotCacheableInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\HttpInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;

/**
 * Answers "would the built-in cache store this response, as it stands right now?".
 *
 * The rules are not invented here; they are the conditions in
 * `Magento\Framework\App\PageCache\Kernel::process()` and the guard in front of it in
 * `Magento\PageCache\Model\App\FrontController\BuiltinPlugin::aroundDispatch()`, read off
 * `vendor/magento/framework/App/PageCache/Kernel.php` and
 * `vendor/magento/module-page-cache/Model/App/FrontController/BuiltinPlugin.php`:
 *
 * - the plugin skips `Kernel::process()` entirely for a response implementing
 *   `NotCacheableInterface`;
 * - `process()` does nothing at all unless `Cache-Control` matches `public.*s-maxage=(\d+)`;
 * - inside that branch it stores only `GET`/`HEAD` requests answered with 200 or 404.
 *
 * The regex below is the same pattern, kept as a constant so a reader can compare the two side by
 * side. Duplicating it is deliberate: core exposes the decision only as a side effect of running
 * it, and a debugging tool that ran the real thing would write to the cache it is meant to observe.
 *
 * **The verdict is a snapshot, not a prediction.** `Magento\Framework\App\FrontController` stamps
 * no-cache headers on the response at the start of every dispatch, and
 * `Magento\PageCache\Model\Layout\LayoutPlugin::afterGenerateElements()` is what promotes a
 * cacheable page to public headers afterwards. A record written before layout generation therefore
 * reads `no` on a page that ends up cached perfectly well — which is exactly why each record also
 * carries the point in the request it was taken from.
 */
class CacheVerdict
{
    private const PUBLIC_CACHE_CONTROL_PATTERN = '/public.*s-maxage=(\d+)/';

    private const CACHEABLE_STATUS_CODES = [200, 404];

    public const VERDICT_YES = 'yes';
    public const VERDICT_NO = 'no';
    public const VERDICT_UNKNOWN = 'unknown';

    public function __construct(
        private readonly HttpRequest $request,
        private readonly PageCacheConfig $pageCacheConfig
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(?HttpInterface $response): array
    {
        $backend = $this->describeBackend();

        if ($response === null) {
            return $this->verdict(
                self::VERDICT_UNKNOWN,
                'no response object in scope at this point in the request',
                null,
                null,
                $backend
            );
        }

        $cacheControl = $this->readHeader($response, 'Cache-Control');
        $status = $response->getHttpResponseCode();

        if ($response instanceof NotCacheableInterface) {
            return $this->verdict(
                self::VERDICT_NO,
                'response implements NotCacheableInterface, which both BuiltinPlugin and Kernel::process() refuse',
                $cacheControl,
                $status,
                $backend
            );
        }

        if ($cacheControl === null) {
            return $this->verdict(
                self::VERDICT_NO,
                'no Cache-Control header on the response yet',
                null,
                $status,
                $backend
            );
        }

        if (!preg_match(self::PUBLIC_CACHE_CONTROL_PATTERN, $cacheControl, $matches)) {
            return $this->verdict(
                self::VERDICT_NO,
                'Cache-Control does not match public + s-maxage, so Kernel::process() returns without storing',
                $cacheControl,
                $status,
                $backend
            );
        }

        if (!$this->request->isGet() && !$this->request->isHead()) {
            return $this->verdict(
                self::VERDICT_NO,
                'Kernel::process() stores GET and HEAD requests only',
                $cacheControl,
                $status,
                $backend
            );
        }

        if (!in_array($status, self::CACHEABLE_STATUS_CODES, true)) {
            return $this->verdict(
                self::VERDICT_NO,
                'Kernel::process() stores responses with status 200 or 404 only',
                $cacheControl,
                $status,
                $backend
            );
        }

        $verdict = $this->verdict(
            self::VERDICT_YES,
            'Cache-Control advertises a public s-maxage and the request is a cacheable GET/HEAD',
            $cacheControl,
            $status,
            $backend
        );
        $verdict['s_maxage'] = (int) $matches[1];

        return $verdict;
    }

    /**
     * @return array<string, mixed>
     */
    private function verdict(
        string $verdict,
        string $reason,
        ?string $cacheControl,
        ?int $status,
        array $backend
    ): array {
        return [
            'verdict' => $verdict,
            'reason' => $reason,
            'cache_control' => $cacheControl,
            'http_status' => $status,
            'backend' => $backend,
        ];
    }

    /**
     * Recorded on every line because the two answers this module gives mean different things
     * depending on what is in front of the response: with Varnish selected, `Kernel::process()`
     * never runs and the same headers are read by the VCL instead.
     *
     * @return array<string, mixed>
     */
    private function describeBackend(): array
    {
        $type = $this->pageCacheConfig->getType();

        return [
            'cache_type_enabled' => $this->pageCacheConfig->isEnabled(),
            'application' => match ($type) {
                PageCacheConfig::BUILT_IN => 'built-in',
                PageCacheConfig::VARNISH => 'varnish',
                default => 'unrecognised (' . $type . ')',
            },
            'configured_ttl' => (int) $this->pageCacheConfig->getTtl(),
        ];
    }

    /**
     * `HttpInterface::getHeader()` answers with `false` when the header is absent, so the boolean
     * has to be sifted out before the value can be read.
     */
    private function readHeader(HttpInterface $response, string $name): ?string
    {
        $header = $response->getHeader($name);

        return $header instanceof HeaderInterface ? $header->getFieldValue() : null;
    }
}
