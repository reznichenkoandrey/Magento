<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Block;

use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Scr1be\BackInStock\Model\Config;

/**
 * The import map, the endpoint config and the entry module tag.
 *
 * **The map has to come first.** A document may install exactly one import map, and only before the
 * first module script begins loading. Hyvä loads Alpine as a module from `before.body.end`, so a map
 * printed anywhere below that is a map the browser rejects — hence `head.additional`.
 *
 * **It carries no per-customer data.** Endpoint urls and nothing else. Everything about the customer
 * arrives through the customer-data section, which is what keeps this block cacheable along with the
 * page it sits in.
 */
class PopupScripts extends Template
{
    /**
     * Bare specifiers, bound here to the published static files and in package.json to the source
     * files. Both maps exist so the same specifiers resolve in a browser and under `node --test`; if
     * you rename one, rename the other.
     */
    private const SCRIPT_ALIASES = [
        'scr1be-back-in-stock/register.js' => 'Scr1be_BackInStock::js/popup-register.js',
        'scr1be-back-in-stock/popup.js' => 'Scr1be_BackInStock::js/popup.js',
        'scr1be-back-in-stock/client.js' => 'Scr1be_BackInStock::js/alert-client.js',
    ];

    private const ENTRY_ALIAS = 'scr1be-back-in-stock/register.js';

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

    /**
     * Every target comes from `getViewFileUrl()`, which resolves through the asset repository — so
     * the urls carry the deployment's static version, respect a separate static domain, and get
     * `.min` appended when `dev/js/minify_files` is on outside developer mode. Hand-written paths are
     * how a module works in developer mode and 404s in production.
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
