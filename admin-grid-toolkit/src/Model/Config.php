<?php
declare(strict_types=1);

namespace Scr1be\AdminGridToolkit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * A master switch and one flag per fix, all read at default scope.
 *
 * Three unrelated defects share one module because they share one deployment: they are all
 * back-office behaviour, they are all one plugin each, and a merchant who hits one of them is
 * usually about to hit the other two. What they do not share is a reason to be switched on
 * together, so each has its own flag and the master switch is folded in here rather than
 * remembered at four call sites.
 *
 * Every read is at default scope, deliberately. All three fixes act on the admin application:
 * a grid export, a grid count and an order created from the admin. An admin request resolves
 * store scope to the admin store, so a website- or store-scoped setting would be editable in the
 * configuration UI and unreachable at runtime — a knob that silently does nothing is worse than
 * no knob at all.
 */
class Config
{
    private const XML_PATH_ENABLED = 'scr1be_admin_grid_toolkit/general/enabled';
    private const XML_PATH_DECODE_EXPORTS = 'scr1be_admin_grid_toolkit/general/decode_exports';
    private const XML_PATH_DEJOIN_GRID_COUNT = 'scr1be_admin_grid_toolkit/general/dejoin_grid_count';
    private const XML_PATH_REORDER_INCREMENT_ID = 'scr1be_admin_grid_toolkit/general/reorder_increment_id';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * Fix 1: legacy grid exports carry the value, not its HTML-escaped rendering.
     */
    public function isExportDecodingEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(self::XML_PATH_DECODE_EXPORTS);
    }

    /**
     * Fix 2: allowlisted LEFT JOINs are dropped from the grid's COUNT(*) select.
     */
    public function isGridCountDejoinEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(self::XML_PATH_DEJOIN_GRID_COUNT);
    }

    /**
     * Fix 3: an admin reorder gets an increment id from the store's sequence.
     */
    public function isReorderIncrementIdFixEnabled(): bool
    {
        return $this->isEnabled() && $this->scopeConfig->isSetFlag(self::XML_PATH_REORDER_INCREMENT_ID);
    }
}
