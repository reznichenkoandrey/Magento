<?php
declare(strict_types=1);

namespace Scr1be\HyvaQuickView\Block;

use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Scr1be\HyvaQuickView\ViewModel\QuickView;

/**
 * The modal's configuration and the entry module, in the page head.
 *
 * The store used to be an inline `alpine:init` block at the top of the modal template, with
 * the endpoint and a translated failure message interpolated into JavaScript source. The
 * translation is the interesting half: only PHP can translate, so the string has to reach the
 * component as data. It travels in the JSON island below rather than as generated code.
 *
 * The inline script also registered no CSP hash, which under an enforced storefront CSP would
 * have meant a Quick view button that opened nothing.
 */
class QuickViewScripts extends Template
{
    private const ENTRY_FILE = 'Scr1be_HyvaQuickView::js/quickview-register.js';

    public function __construct(
        Context $context,
        private readonly JsonHexTag $jsonSerializer,
        private readonly QuickView $quickView,
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
            'infoUrl' => $this->quickView->getInfoEndpoint(),
            'errorTitle' => (string) __('Could not load product'),
        ]);
    }
}
