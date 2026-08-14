<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Block;

use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * The import map and the entry module tag — a block of its own, in the page head, on purpose.
 *
 * Two reasons it is not part of the menu block.
 *
 * **An import map has to come first.** The HTML specification lets a document install one only
 * before the first module script starts loading, and Hyvä loads Alpine as a module from
 * `before.body.end`. A map printed anywhere below that is a map the browser rejects. Rendering it
 * from `head.additional` puts it in front of every module script on the page, including Alpine's.
 *
 * **It must not be cached with the menu.** An import map is an inline script, so on a storefront
 * with a strict policy it needs a CSP hash — and the hash is registered while the template runs.
 * A menu block with a `cache_lifetime` skips its template on a hit, so a map that travelled
 * inside it would be served with no hash behind it and blocked. This block has no lifetime, and
 * the two lines it renders are why that costs nothing.
 */
class MenuScripts extends Template
{
    /**
     * Bare specifiers, bound here to the published static files and in package.json to the source
     * files. Both maps exist so the same three specifiers resolve in a browser and under
     * `node --test`; if you rename one, rename the other.
     */
    private const SCRIPT_ALIASES = [
        'scr1be-mega-menu/register.js' => 'Scr1be_HyvaMegaMenu::js/mega-menu-register.js',
        'scr1be-mega-menu/component.js' => 'Scr1be_HyvaMegaMenu::js/mega-menu.js',
        'scr1be-mega-menu/state.js' => 'Scr1be_HyvaMegaMenu::js/menu-state.js',
    ];

    private const ENTRY_ALIAS = 'scr1be-mega-menu/register.js';

    public function __construct(
        Context $context,
        private readonly JsonHexTag $jsonSerializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The map, already serialised.
     *
     * Every target comes from `getViewFileUrl()`, which resolves through the asset repository —
     * so the urls carry the deployment's static version and respect a separate static domain, and
     * `Asset\File::getPath()` runs the result through `Asset\Minification::addMinifiedSign()`, which
     * appends `.min` when `dev/js/minify_files` is on outside developer mode. Writing the paths by
     * hand is how a module works in developer mode and 404s in production.
     */
    public function getImportMapJson(): string
    {
        $imports = [];

        foreach (self::SCRIPT_ALIASES as $specifier => $fileId) {
            $imports[$specifier] = $this->getViewFileUrl($fileId);
        }

        return $this->jsonSerializer->serialize(['imports' => $imports]);
    }

    public function getEntryScriptUrl(): string
    {
        return $this->getViewFileUrl(self::SCRIPT_ALIASES[self::ENTRY_ALIAS]);
    }
}
