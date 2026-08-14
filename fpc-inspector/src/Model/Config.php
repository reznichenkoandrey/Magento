<?php
declare(strict_types=1);

namespace Scr1be\FpcInspector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Settings for the recorder.
 *
 * Every read here deliberately uses the *default* scope and never passes a store code. The earliest
 * hook fires inside `Kernel::load()`, which Magento_PageCache's `BuiltinPlugin` runs before the
 * front controller has dispatched an action at all — and the store only reaches the HTTP context
 * once Magento_Store's `beforeDispatch` plugin on `AbstractAction` has run. Asking for a
 * store-scoped value from inside that window would either resolve to a store the request has not
 * been assigned to yet or drag store resolution forward, and dragging anything forward is an
 * unacceptable side effect for a tool whose whole job is to observe without disturbing.
 *
 * The admin form matches: it offers no website/store switches, so nothing is promised that
 * cannot be honoured.
 */
class Config
{
    private const XML_PATH_ENABLED = 'scr1be_fpc_inspector/general/enabled';
    private const XML_PATH_URI_FILTER = 'scr1be_fpc_inspector/general/uri_filter';
    private const XML_PATH_STACK_DEPTH = 'scr1be_fpc_inspector/general/stack_depth';
    private const XML_PATH_LOG_VARY = 'scr1be_fpc_inspector/general/log_vary';
    private const XML_PATH_LOG_NO_CACHE = 'scr1be_fpc_inspector/general/log_no_cache';

    /**
     * Guard rails around the admin-supplied frame count. The lower bound keeps a record from being
     * written with no stack at all (a record without a caller answers nothing); the upper bound
     * keeps a mistyped value from turning every record into a wall of framework plumbing.
     */
    public const MIN_STACK_DEPTH = 1;
    public const MAX_STACK_DEPTH = 50;
    public const DEFAULT_STACK_DEPTH = 12;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function isVaryChannelEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LOG_VARY);
    }

    public function isNoCacheChannelEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LOG_NO_CACHE);
    }

    public function getStackDepth(): int
    {
        $configured = (int) $this->scopeConfig->getValue(self::XML_PATH_STACK_DEPTH);

        if ($configured < self::MIN_STACK_DEPTH) {
            return self::DEFAULT_STACK_DEPTH;
        }

        return min($configured, self::MAX_STACK_DEPTH);
    }

    /**
     * @return string[] Substrings; an empty list means "no filter, record everything".
     */
    public function getUriNeedles(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_PATH_URI_FILTER);

        $needles = array_map('trim', explode(',', $raw));

        return array_values(array_filter($needles, static fn (string $needle): bool => $needle !== ''));
    }

    /**
     * Substring matching rather than a regex on purpose: the field is typed by someone mid-debug
     * who wants to narrow the log to one page, and an unescaped `?` or `.` in a pasted URL would
     * silently widen a regex instead of narrowing it.
     */
    public function matchesUri(string $uri): bool
    {
        $needles = $this->getUriNeedles();

        if ($needles === []) {
            return true;
        }

        foreach ($needles as $needle) {
            if (str_contains($uri, $needle)) {
                return true;
            }
        }

        return false;
    }
}
