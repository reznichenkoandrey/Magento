<?php
declare(strict_types=1);

namespace Scr1be\HyvaLazyImages\Block;

use Magento\Framework\View\Element\Template;

/**
 * The entry module for the `data-src` fallback loader.
 *
 * There is no configuration island here, unlike the sibling modules: the loader takes no
 * endpoints and no translated copy, only a root margin and a threshold that are properties of
 * the technique rather than of the store. Adding an island to carry two constants nobody
 * changes would be ceremony.
 *
 * What did change is that the loader is no longer an IIFE inside an inline `<script>`. That
 * script registered no CSP hash — and this module's own `picture.phtml` carries a comment
 * explaining why it avoids inline attribute handlers on exactly those grounds, which made the
 * inline block next to it the odd one out.
 */
class LazyScripts extends Template
{
    private const ENTRY_FILE = 'Scr1be_HyvaLazyImages::js/lazy-register.js';

    public function getEntryScriptUrl(): string
    {
        return $this->getViewFileUrl(self::ENTRY_FILE);
    }
}
