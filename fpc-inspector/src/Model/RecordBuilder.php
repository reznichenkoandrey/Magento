<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Model;

use Magento\Framework\App\Http\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\FpcInspector\Model\Inspector\CacheVerdict;
use Scr1be\FpcInspector\Model\Inspector\StackTrace;
use Scr1be\FpcInspector\Model\Inspector\VaryBreakdown;

/**
 * Assembles the one record shape both channels write.
 *
 * The three fields that carry the most weight are `vary`, `vary_cookie` and `vary_matches_cookie`,
 * and it is worth being precise about what each one is for in 2.4.8, because the folklore about
 * this corner of Magento is older than the code.
 *
 * The cache key is built by `Magento\PageCache\Model\App\Request\Http\IdentifierForSave::getValue()`
 * from the **computed** vary string — never from the cookie. Magento_PageCache's `etc/di.xml` makes
 * that class the preference for `IdentifierInterface`, and its `etc/frontend/di.xml` also passes it
 * as `Kernel`'s `identifierForSave` argument, so `Kernel::load()` and `Kernel::process()` end up
 * hashing the same inputs. (The older, cookie-preferring
 * `Magento\Framework\App\PageCache\Identifier` still exists — `IdentifierForSave` borrows its
 * marketing-parameter list — but it is not what the storefront cache keys off.) That key is also
 * more than the vary string: `IdentifierStoreReader::getPageTagsWithStoreCacheTags()` folds in the
 * user-agent design exception and the `MAGE_RUN_TYPE`/`MAGE_RUN_CODE` server values before hashing.
 *
 * So the cookie is not a cache key. What it is, is a tripwire.
 * `Magento\PageCache\Model\App\Response\HttpPlugin::beforeSendResponse()` compares the computed vary
 * against the `X-Magento-Vary` cookie the browser sent and, when the two differ, stamps no-cache on
 * the response before re-sending the cookie. That single strict comparison is why the two questions
 * this module answers so often turn out to be one question, and it is what `vary_matches_cookie`
 * reports. `cookie_action` then says what `Response\Http::sendVary()` is about to do with the
 * cookie, which is how a reader recognises the request that will look different from the next one.
 *
 * Store and currency are read out of the HTTP context rather than from the store manager on
 * purpose: what matters for a cache key is the value the key was built from, and reaching for the
 * store manager would report a value the cache never saw.
 */
class RecordBuilder
{
    public const CHANNEL_VARY = 'vary';
    public const CHANNEL_NO_CACHE = 'no-cache';

    /** The vary cookie is written with the freshly computed value. */
    public const COOKIE_ACTION_SET = 'set';

    /** The context went empty while a cookie was still on the request, so the cookie is deleted. */
    public const COOKIE_ACTION_DELETE = 'delete';

    /** Nothing to write and nothing to clear. */
    public const COOKIE_ACTION_NONE = 'none';

    public function __construct(
        private readonly HttpRequest $request,
        private readonly Config $config,
        private readonly RequestScope $scope,
        private readonly VaryBreakdown $breakdown,
        private readonly CacheVerdict $verdict,
        private readonly StackTrace $stackTrace
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $channel, Context $context, ?string $varyString, ?HttpInterface $response): array
    {
        $cookie = $this->readVaryCookie();
        $explained = $this->breakdown->explain($context);

        return [
            'request_id' => $this->scope->getRequestId(),
            'seq' => $this->scope->nextSequence(),
            'channel' => $channel,
            'uri' => $this->request->getUriString(),
            'method' => $this->request->getMethod(),
            'store' => $this->readContextString($context, StoreManagerInterface::CONTEXT_STORE),
            'currency' => $this->readContextString($context, Context::CONTEXT_CURRENCY),
            'vary' => $varyString,
            'vary_cookie' => $cookie,
            // Strict, because core's own comparison in HttpPlugin::beforeSendResponse() is strict:
            // false here is the exact condition under which core stamps no-cache on this response.
            'vary_matches_cookie' => $varyString === $cookie,
            'cookie_action' => $this->predictCookieAction($varyString, $cookie),
            'contributors' => $explained['contributors'],
            'inert' => $explained['inert'],
            'will_cache' => $this->verdict->evaluate($response),
            'stack' => $this->stackTrace->capture($this->config->getStackDepth()),
        ];
    }

    /**
     * Mirrors the branch in `Response\Http::sendVary()`: a truthy vary string is written to the
     * cookie, an empty one clears a cookie that is still on the request, and neither of those means
     * the cookie is left alone. Reported because a request that rewrites the cookie is a request
     * whose successor will behave differently, and that is easy to miss when reading a log after
     * the fact.
     */
    private function predictCookieAction(?string $varyString, ?string $cookie): string
    {
        if ((bool) $varyString) {
            return self::COOKIE_ACTION_SET;
        }

        return (bool) $cookie ? self::COOKIE_ACTION_DELETE : self::COOKIE_ACTION_NONE;
    }

    /**
     * Read exactly the way core reads it, so the recorded value is the one core compared against:
     * `HttpPlugin` pulls the cookie off the *request* rather than from the cookie manager, which
     * means a cookie set earlier in this same request is deliberately not visible here.
     */
    private function readVaryCookie(): ?string
    {
        $cookie = $this->request->get(HttpResponse::COOKIE_VARY_STRING);

        return is_string($cookie) ? $cookie : null;
    }

    private function readContextString(Context $context, string $key): ?string
    {
        $value = $context->getValue($key);

        return is_scalar($value) ? (string) $value : null;
    }
}
