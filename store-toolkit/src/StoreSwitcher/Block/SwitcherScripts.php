<?php
declare(strict_types=1);

namespace Scr1be\StoreSwitcher\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Scr1be\StoreSwitcher\Model\StoreListProvider;

/**
 * The one `<script type="module">` tag the switcher needs.
 *
 * Separate from the two switcher blocks because it belongs at the end of the body while they
 * belong in the header, and because a single store view should cost a visitor no JavaScript at
 * all — the same `isSwitchable()` gate that hides the controls also drops the module.
 */
class SwitcherScripts extends Template
{
    private StoreListProvider $storeListProvider;

    public function __construct(
        Context $context,
        StoreListProvider $storeListProvider,
        array $data = []
    ) {
        $this->storeListProvider = $storeListProvider;

        parent::__construct($context, $data);
    }

    public function isSwitchable(): bool
    {
        return count($this->storeListProvider->getOptions(false)) > 1;
    }

    public function getEntryScriptUrl(): string
    {
        return $this->getViewFileUrl('Scr1be_StoreSwitcher::js/store-switcher.js');
    }
}
