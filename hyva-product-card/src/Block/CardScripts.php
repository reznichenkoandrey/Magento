<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Scr1be\HyvaProductCard\ViewModel\ProductCard;

/**
 * The endpoint config and the entry module tag — one small block in the page head.
 *
 * **There is deliberately no import map.** The entry module already imported its siblings by
 * relative path, so the map this block used to print bound four specifiers nothing asked for — and
 * it was not free: a document installs the first import map it declares and Firefox rejects every
 * one after it, so this map, sitting second of the three the storefront printed, was enough to stop
 * the third (the slider's) from ever applying. Relative imports need no map and cannot collide with
 * another module's.
 *
 * **The block still does not travel inside the card.** Hyvä's card block carries a `cache_lifetime`
 * and skips its template on a hit; the config and the script tag have to be on every response.
 */
class CardScripts extends Template
{
    /**
     * The published file behind the entry module.
     *
     * Resolved through `getViewFileUrl()` rather than written out: the asset repository is what puts
     * the deployment's static version into the url, respects a separate static domain, and appends
     * `.min` via `Asset\Minification::addMinifiedSign()` when `dev/js/minify_files` is on outside
     * developer mode. A hand-written path is how a module works in developer mode and 404s in
     * production.
     */
    private const ENTRY_FILE = 'Scr1be_HyvaProductCard::js/card-register.js';

    public function __construct(
        Context $context,
        private readonly ProductCard $cardViewModel,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getEntryScriptUrl(): string
    {
        return $this->getViewFileUrl(self::ENTRY_FILE);
    }

    /**
     * Endpoint urls and the analytics switch, for the components to read once instead of every card
     * carrying its own copy.
     */
    public function getCardConfigJson(): string
    {
        return $this->cardViewModel->getCardConfigJson();
    }

    public function isEnabled(): bool
    {
        return $this->cardViewModel->isEnabled();
    }
}
