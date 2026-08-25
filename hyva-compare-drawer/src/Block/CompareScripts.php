<?php
declare(strict_types=1);

namespace Scr1be\HyvaCompareDrawer\Block;

use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * The store's configuration and the entry module, in the page head.
 *
 * The store used to be an object literal inside an inline `alpine:init` block in
 * `store.phtml`, rendered from `before.body.end`. It worked, and it cost two things: nothing
 * about the list — the cap, the eviction order, the cross-tab sync — could be tested without
 * a browser, and the inline script registered no CSP hash, so an enforced storefront CSP
 * would have left the drawer permanently empty.
 *
 * **The head, not `before.body.end`.** A module script is deferred and deferred scripts run in
 * document order; Hyvä's Alpine tag is the first block in `before.body.end`, so an entry
 * module rendered after it would register the store after Alpine had already started.
 *
 * **No import map.** `compare-register.js` imports `./compare-store.js` relatively. The only
 * map on this storefront belongs to `Scr1be_HyvaProductSlider`.
 */
class CompareScripts extends Template
{
    private const ENTRY_FILE = 'Scr1be_HyvaCompareDrawer::js/compare-register.js';

    /**
     * Versioned key: a shape change in a stored item would otherwise meet a list written by
     * the previous version and render half-blank rows.
     */
    private const STORAGE_KEY = 'scr1be_compare_v1';

    /** Four is what the drawer's grid is laid out for; the store evicts the oldest beyond it. */
    private const MAX_ITEMS = 4;

    public function __construct(
        Context $context,
        private readonly JsonHexTag $jsonSerializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getEntryScriptUrl(): string
    {
        return $this->getViewFileUrl(self::ENTRY_FILE);
    }

    public function getConfigJson(): string
    {
        return $this->jsonSerializer->serialize([
            'storageKey' => self::STORAGE_KEY,
            'maxItems' => self::MAX_ITEMS,
        ]);
    }
}
