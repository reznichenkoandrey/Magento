<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Block;

use Magento\Framework\View\Element\Template;

/**
 * The entry module tag — one line, from a block of its own in the page head.
 *
 * **There is deliberately no import map here.** A document may install one only before its first
 * module script, and Firefox honours the first map it sees and rejects the rest; a storefront
 * running three Hyvä modules that each printed their own therefore lost two of them, silently, with
 * no console error on the server side and none in Chrome. The entry module imports its siblings by
 * relative path instead, which the browser resolves against this file's own url — no map, no
 * ordering contract with any other module, and nothing to go wrong when a fourth module arrives.
 *
 * **It is still not part of the menu block.** The menu carries a `cache_lifetime` and skips its
 * template on a hit; the script tag must be emitted on every request, including cached ones.
 */
class MenuScripts extends Template
{
    /**
     * The published file behind the entry module.
     *
     * Resolved through `getViewFileUrl()` rather than written out, because the asset repository is
     * what puts the deployment's static version into the url, honours a separate static domain, and
     * runs the path through `Asset\Minification::addMinifiedSign()` — which appends `.min` when
     * `dev/js/minify_files` is on outside developer mode. A hand-written path is how a module works
     * in developer mode and 404s in production.
     */
    private const ENTRY_FILE = 'Scr1be_HyvaMegaMenu::js/mega-menu-register.js';

    public function getEntryScriptUrl(): string
    {
        return $this->getViewFileUrl(self::ENTRY_FILE);
    }
}
