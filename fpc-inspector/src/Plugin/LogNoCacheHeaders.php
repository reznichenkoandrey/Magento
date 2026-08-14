<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Plugin;

use Magento\Framework\App\Http\Context;
use Magento\Framework\App\Response\Http as HttpResponse;
use Scr1be\FpcInspector\Model\RecordBuilder;
use Scr1be\FpcInspector\Model\Recorder;
use Scr1be\FpcInspector\Model\RecordingGate;
use Scr1be\FpcInspector\Model\RequestScope;

/**
 * Records every call to `Magento\Framework\App\Response\Http::setNoCacheHeaders()` together with
 * the `Cache-Control` value it is about to overwrite and the stack of whoever called it.
 *
 * **Why `before`.** The interesting value is the one that is about to be destroyed. `after` would
 * read back `no-store, no-cache, must-revalidate, max-age=0` on every single line — the answer this
 * module was installed to explain, not evidence about it. Running first is what turns the record
 * into "somebody replaced `public, max-age=86400, s-maxage=86400` from here".
 *
 * **What to expect in the log.** Core reaches this method from more than one place on an ordinary
 * request, and only one of them is a problem:
 *
 * - `Magento\Framework\App\FrontController::processRequest()` calls it at the top of every dispatch
 *   (`vendor/magento/framework/App/FrontController.php`). No-cache is the *default* state of a
 *   Magento response; a page becomes cacheable later, when
 *   `Magento\PageCache\Model\Layout\LayoutPlugin::afterGenerateElements()` sets public headers on a
 *   layout that reports itself cacheable. A record from this caller means nothing on its own.
 * - `Magento\Framework\App\PageCache\Kernel::process()` calls it as soon as it sees a public
 *   `Cache-Control`, before the status-code and request-method checks that decide whether the entry
 *   is written (`vendor/magento/framework/App/PageCache/Kernel.php`) — it is flattening the copy
 *   going to the browser, not refusing to cache. A record from this caller means the page
 *   advertised itself as cacheable; whether it was stored is what `will_cache` reports.
 * - `Magento\PageCache\Model\App\Response\HttpPlugin::beforeSendResponse()` calls it when the
 *   computed vary string and the `X-Magento-Vary` cookie disagree
 *   (`vendor/magento/module-page-cache/Model/App/Response/HttpPlugin.php`). This one runs during
 *   `sendResponse()`, which `Bootstrap::run()` calls only after `launch()` has returned — so the
 *   built-in cache has already made its own decision by then, and what these headers change is what
 *   every cache *downstream* of PHP is told. `vary_matches_cookie` on the same record says in one
 *   field whether this is the caller.
 *
 * Distinguishing the three is exactly what the stack is for, which is why the caller is part of the
 * de-duplication fingerprint: each distinct call site earns its own line instead of the first one
 * hiding the rest.
 */
class LogNoCacheHeaders
{
    public function __construct(
        private readonly RecordingGate $gate,
        private readonly RequestScope $scope,
        private readonly RecordBuilder $builder,
        private readonly Recorder $recorder,
        private readonly Context $context
    ) {
    }

    public function beforeSetNoCacheHeaders(HttpResponse $subject): void
    {
        if (!$this->gate->allows(RecordBuilder::CHANNEL_NO_CACHE)) {
            return;
        }

        $this->scope->beginRecording();

        try {
            // Asking the context for its vary string re-enters the interceptor the sibling hook is
            // attached to. That is what the recording flag set above is for: the vary hook sees it
            // and stands down, so this question does not get logged as somebody else's answer.
            $varyString = $this->context->getVaryString();
            $record = $this->builder->build(
                RecordBuilder::CHANNEL_NO_CACHE,
                $this->context,
                is_string($varyString) ? $varyString : null,
                $subject
            );

            if ($this->scope->isFirstSighting($this->fingerprint($record))) {
                $this->recorder->record($record);
            }
        } catch (\Throwable $error) {
            $this->recorder->failed($error);
        } finally {
            $this->scope->endRecording();
        }
    }

    /**
     * The overwritten `Cache-Control` is part of the fingerprint as well as the call site: the same
     * caller replacing public headers is a different event from the same caller re-stamping a
     * response that was already no-cache, and a reader wants both lines.
     *
     * @param array<string, mixed> $record
     */
    private function fingerprint(array $record): string
    {
        $stack = is_array($record['stack'] ?? null) ? $record['stack'] : [];
        $cacheControl = $record['will_cache']['cache_control'] ?? null;

        return implode('|', [
            RecordBuilder::CHANNEL_NO_CACHE,
            is_string($cacheControl) ? $cacheControl : 'none',
            (string) ($stack[0] ?? 'unknown'),
        ]);
    }
}
