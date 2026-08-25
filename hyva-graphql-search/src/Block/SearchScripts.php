<?php
declare(strict_types=1);

namespace Scr1be\HyvaGraphqlSearch\Block;

use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Scr1be\HyvaGraphqlSearch\ViewModel\SearchConfig;

/**
 * The endpoint configuration and the entry module, in the page head.
 *
 * The component used to live in a `<script>` block inside the search bar template, reading
 * six `SCR1BE_*` globals written above it. Two things were wrong with that beyond taste: the
 * code could not be tested without a storefront, and the inline script registered no CSP
 * hash — so the header's search box was one `csp/mode/storefront = enforced` away from
 * silently not booting.
 *
 * **There is deliberately no import map.** `search-register.js` imports `./instant-search.js`
 * by relative path. The only map the storefront prints belongs to `Scr1be_HyvaProductSlider`,
 * whose engine specifier is a rebindable di.xml seam; a document installs the first map it
 * declares and Firefox rejects the rest, so a second one here would be dead weight at best.
 *
 * **In the head, not `before.body.end`.** A module script is deferred and deferred scripts run
 * in document order, so an entry module rendered after Hyvä's Alpine tag runs after Alpine has
 * already walked the DOM — and every search box would report an unregistered component.
 */
class SearchScripts extends Template
{
    /**
     * Resolved through `getViewFileUrl()` rather than written out: the asset repository stamps
     * the deployment's static version into the url, honours a separate static domain, and
     * appends `.min` via `Asset\Minification::addMinifiedSign()` when `dev/js/minify_files` is
     * on outside developer mode.
     */
    private const ENTRY_FILE = 'Scr1be_HyvaGraphqlSearch::js/search-register.js';

    /** Matches the page size the old inline script hardcoded. */
    private const PAGE_SIZE = 8;

    /** Five minutes, as before: long enough to make backspacing free, short enough to stay honest. */
    private const CACHE_TTL_MS = 300000;

    public function __construct(
        Context $context,
        private readonly JsonHexTag $jsonSerializer,
        private readonly SearchConfig $searchConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getEntryScriptUrl(): string
    {
        return $this->getViewFileUrl(self::ENTRY_FILE);
    }

    /**
     * Serialised with `JSON_HEX_TAG`, so no url in it can close the element it travels in.
     */
    public function getConfigJson(): string
    {
        return $this->jsonSerializer->serialize([
            'graphqlUrl' => $this->searchConfig->getGraphqlEndpoint(),
            'searchResultUrl' => $this->searchConfig->getSearchResultUrl(),
            'productUrlSuffix' => $this->searchConfig->getProductUrlSuffix(),
            'pageSize' => self::PAGE_SIZE,
            'cacheTtlMs' => self::CACHE_TTL_MS,
            'minQuery' => $this->searchConfig->getMinQueryLength(),
        ]);
    }
}
