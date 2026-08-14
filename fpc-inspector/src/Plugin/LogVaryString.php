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
 * Records every distinct answer `Magento\Framework\App\Http\Context::getVaryString()` gives, and
 * who asked for it.
 *
 * **Why `after` and not `before`.** The question is what the vary string *is*, which only exists
 * once core has filtered the context and hashed the survivors. `before` would also arrive too
 * early to be trustworthy: `Magento_Persistent` installs its own `beforeGetVaryString()` plugin
 * that writes a key into the context on the way in
 * (`vendor/magento/module-persistent/Model/Plugin/PersistentCustomerContext.php`, declared in that
 * module's `etc/frontend/di.xml`), so a `before` observer would report a context that is still one
 * write short of the one that gets hashed.
 *
 * **Why not `around`.** An `around` plugin on a method called several times per request buys
 * nothing here and puts a closure between core and its own implementation for the lifetime of the
 * install. Reading the result is enough.
 *
 * The method is called more than once per request — the loading key, the saving key and the vary
 * cookie are three separate callers — so the record is fingerprinted by (channel, value, call site)
 * and repeats are dropped. See `RequestScope` for what that policy keeps and what it throws away.
 */
class LogVaryString
{
    /**
     * The shared response is injected so a vary record can carry the same cacheability verdict as a
     * no-cache record, and it is the right object to look at on the path that matters:
     * `App\Http::launch()` hands this instance to `renderResult()`, and
     * `Magento\PageCache\Model\Controller\Result\BuiltinPlugin` passes that same argument straight
     * into `Kernel::process()`. It is *not* the delivered response in the two cases where
     * `launch()` swaps `$this->_response` for something else — a controller returning an
     * `HttpInterface` of its own, and a full page cache hit, where `Kernel::buildResponse()` mints
     * a fresh response from the stored entry. Neither reaches `Kernel::process()`, so nothing is
     * being stored on those paths anyway; the verdict on such a line describes the bootstrap
     * response rather than the delivered one, and the stack says which path it came from.
     *
     * Holding the response costs nothing at construction time: the application builds it before it
     * dispatches, so this plugin never causes it to be created.
     */
    public function __construct(
        private readonly RecordingGate $gate,
        private readonly RequestScope $scope,
        private readonly RecordBuilder $builder,
        private readonly Recorder $recorder,
        private readonly HttpResponse $response
    ) {
    }

    /**
     * `$result` is typed `mixed` rather than `?string` so the value is handed back exactly as it
     * arrived. The generated interceptors carry no `declare(strict_types=1)` — check any file under
     * `generated/code/**\/Interceptor.php` — so PHP's weak mode applies at this call site, and a
     * narrower parameter type here would let a coercion rewrite a value this module only ever meant
     * to observe.
     */
    public function afterGetVaryString(Context $subject, mixed $result): mixed
    {
        if (!$this->gate->allows(RecordBuilder::CHANNEL_VARY)) {
            return $result;
        }

        $this->scope->beginRecording();

        try {
            $varyString = is_string($result) ? $result : null;
            $record = $this->builder->build(
                RecordBuilder::CHANNEL_VARY,
                $subject,
                $varyString,
                $this->response
            );

            if ($this->scope->isFirstSighting($this->fingerprint($record))) {
                $this->recorder->record($record);
            }
        } catch (\Throwable $error) {
            $this->recorder->failed($error);
        } finally {
            $this->scope->endRecording();
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function fingerprint(array $record): string
    {
        $stack = is_array($record['stack'] ?? null) ? $record['stack'] : [];

        return implode('|', [
            RecordBuilder::CHANNEL_VARY,
            (string) ($record['vary'] ?? 'null'),
            (string) ($stack[0] ?? 'unknown'),
        ]);
    }
}
