<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductCard\Block;

use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Scr1be\HyvaProductCard\ViewModel\ProductCard;

/**
 * The import map, the endpoint config and the entry module tag — one small block in the page head.
 *
 * **An import map has to come first.** The HTML specification lets a document install one only
 * before the first module script starts loading, and Hyvä loads Alpine as a module from
 * `before.body.end`. A map printed below that is a map the browser rejects. Rendering from
 * `head.additional` puts it in front of every module script on the page.
 *
 * **It must not travel inside a cached block.** An import map is an inline script, so a strict-CSP
 * storefront needs a hash for it — and the hash is registered while the template runs. Hyvä's card
 * block carries a `cache_lifetime` and skips its template on a hit, so a map rendered from inside
 * a card would eventually be served with no hash behind it and blocked. This block has no lifetime,
 * and the three lines it renders are why that costs nothing.
 */
class CardScripts extends Template
{
    /**
     * Bare specifiers, bound here to the published static files and in package.json to the source
     * files. Both maps exist so the same specifiers resolve in a browser and under `node --test`;
     * if you rename one, rename the other.
     */
    private const SCRIPT_ALIASES = [
        'scr1be-product-card/register.js' => 'Scr1be_HyvaProductCard::js/card-register.js',
        'scr1be-product-card/card.js' => 'Scr1be_HyvaProductCard::js/card.js',
        'scr1be-product-card/grid.js' => 'Scr1be_HyvaProductCard::js/card-grid.js',
        'scr1be-product-card/data.js' => 'Scr1be_HyvaProductCard::js/card-data.js',
    ];

    private const ENTRY_ALIAS = 'scr1be-product-card/register.js';

    public function __construct(
        Context $context,
        private readonly JsonHexTag $jsonSerializer,
        private readonly ProductCard $cardViewModel,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Every target comes from `getViewFileUrl()`, which resolves through the asset repository — so
     * the urls carry the deployment's static version, respect a separate static domain, and get
     * `.min` appended by `Asset\Minification::addMinifiedSign()` when `dev/js/minify_files` is on
     * outside developer mode. Writing the paths by hand is how a module works in developer mode
     * and 404s in production.
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
