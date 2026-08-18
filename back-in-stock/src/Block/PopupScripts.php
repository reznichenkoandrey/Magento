<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Block;

use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Scr1be\BackInStock\Model\Config;

/**
 * The endpoint config and the entry module tag, in the page head.
 *
 * **There is deliberately no import map.** The entry module imports its siblings by relative path,
 * so the map this block used to print bound three specifiers nothing asked for. It was not free: a
 * document installs the first import map it declares and Firefox rejects every one after it, and
 * this block renders from `default.xml` — every page, after the slider's map, which is ordered
 * `before="-"` in the same container. The map was therefore never installed in Firefox, while
 * standing ready to swallow the first real bare specifier this module added. The one remaining map
 * on the storefront belongs to `Scr1be_HyvaProductSlider`, whose engine specifier is a rebindable
 * di.xml seam rather than plumbing.
 *
 * `package.json` still exports the same three names. That map is the Node half only — it is what
 * lets the specs import exactly the files the storefront loads, under `node --test`, where relative
 * paths from a spec directory would name something else.
 *
 * **It carries no per-customer data.** Endpoint urls and nothing else. Everything about the customer
 * arrives through the customer-data section, which is what keeps this block cacheable along with the
 * page it sits in.
 */
class PopupScripts extends Template
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
    private const ENTRY_FILE = 'Scr1be_BackInStock::js/popup-register.js';

    public function __construct(
        Context $context,
        private readonly JsonHexTag $jsonSerializer,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->config->isPopupEnabled((int)$this->_storeManager->getStore()->getId());
    }

    public function getEntryScriptUrl(): string
    {
        return $this->getViewFileUrl(self::ENTRY_FILE);
    }

    /**
     * `_secure => true` on both, because the popup posts a form key and a session cookie: an
     * `http://` action on an `https://` page is a downgrade the browser will not warn about.
     */
    public function getConfigJson(): string
    {
        return $this->jsonSerializer->serialize([
            'endpoints' => [
                'dismiss' => $this->getUrl('scr1be_backinstock/alert/dismiss', ['_secure' => true]),
                'addToCart' => $this->getUrl('scr1be_backinstock/alert/addtocart', ['_secure' => true]),
            ],
        ]);
    }
}
