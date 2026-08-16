<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Block;

use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Scr1be\HyvaProductSlider\Model\Config;

/**
 * The import map and the entry module, in the page head.
 *
 * **An import map has to come first.** The HTML specification lets a document install one only before
 * the first module script starts loading, and Hyvä loads Alpine as a module from `before.body.end`. A
 * map printed below that is a map the browser rejects. Rendering from `head.additional` puts it in
 * front of every module script on the page.
 *
 * **It is the only map the storefront prints.** Firefox installs the first import map a document
 * declares and rejects every one after it; this module's used to be the third of three, so the
 * slider silently never resolved its engine. The mega menu and the product card now import their
 * siblings relatively and print no map at all. This one stays because the engine specifier below is
 * a deliberate seam rather than internal plumbing.
 *
 * **It must not travel inside a cached block.** An import map is an inline script, so a strict-CSP
 * storefront needs a hash for it, and the hash is registered while the template runs. A map rendered
 * from inside the slider block — which does carry a cache lifetime — would eventually be served from
 * cache with no hash behind it and be blocked. This block has no lifetime, and the three lines it
 * renders are why that costs nothing.
 *
 * **Both stay global, unlike the product card's bundle.** Gating them on a slider having rendered was
 * tried through Hyvä's `BlockJsDependencies`, which renders the dependency from `before.body.end` —
 * and a `<script type="module">` is deferred, so it then ran after Alpine had already walked the page
 * looking for a component nobody had registered. A widget can also be dropped on any page, which the
 * layout cannot see. Three lines of markup and one small module is the price of both.
 *
 * **The aliases are a di.xml argument, not a constant.** That is what makes the slider engine
 * swappable: `scr1be-product-slider/engine.js` is a bare specifier bound here to the module's own
 * scroll-snap engine, and a project that wants a different one rebinds the specifier in di.xml
 * without touching a template or a component. That seam is the reason this module keeps a map at all
 * when the other two dropped theirs. The contract the replacement must satisfy is in the README.
 */
class SliderScripts extends Template
{
    public const ENTRY_ALIAS = 'scr1be-product-slider/register.js';

    /** @var array<string, string> */
    private const DEFAULT_SCRIPT_ALIASES = [
        'scr1be-product-slider/register.js' => 'Scr1be_HyvaProductSlider::js/slider-register.js',
        'scr1be-product-slider/slider.js' => 'Scr1be_HyvaProductSlider::js/slider.js',
        'scr1be-product-slider/engine.js' => 'Scr1be_HyvaProductSlider::js/engine-scroll-snap.js',
        'scr1be-product-slider/proof.js' => 'Scr1be_HyvaProductSlider::js/social-proof.js',
    ];

    /** @var array<string, string> */
    private array $scriptAliases;

    /**
     * @param array<string, string> $scriptAliases
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly JsonHexTag $jsonSerializer,
        private readonly Config $config,
        array $scriptAliases = [],
        array $data = []
    ) {
        parent::__construct($context, $data);

        $this->scriptAliases = $scriptAliases === [] ? self::DEFAULT_SCRIPT_ALIASES : $scriptAliases;
    }

    /**
     * Every target comes from `getViewFileUrl()`, which resolves through the asset repository — so the
     * urls carry the deployment's static version, respect a separate static domain, and get `.min`
     * appended by `Asset\Minification::addMinifiedSign()` when `dev/js/minify_files` is on outside
     * developer mode. Writing the paths by hand is how a module works in developer mode and 404s in
     * production.
     */
    public function getImportMapJson(): string
    {
        $imports = [];

        foreach ($this->scriptAliases as $specifier => $fileId) {
            $imports[$specifier] = $this->getViewFileUrl($fileId);
        }

        return $this->jsonSerializer->serialize(['imports' => $imports]);
    }

    public function getEntryScriptUrl(): string
    {
        return $this->getViewFileUrl($this->scriptAliases[self::ENTRY_ALIAS] ?? '');
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled((int) $this->_storeManager->getStore()->getId());
    }
}
