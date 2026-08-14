<?php
declare(strict_types=1);

namespace Scr1be\StoreSwitcher\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Scr1be\StoreSwitcher\Model\FlagSprite;
use Scr1be\StoreSwitcher\Model\StoreListProvider;
use Scr1be\StoreSwitcher\Model\StoreOption;

/**
 * The desktop switcher: a real `<select>` whose options already carry finished redirect URLs.
 *
 * Server-rendering the URLs is only safe because this block is never block-cached. Every option
 * contains a `uenc` of the current request (built by Magento\Store\ViewModel\SwitcherUrlProvider
 * from Store::getCurrentUrl()), so one cached copy served on a second URL would send every
 * switcher on the site back to whichever page happened to warm the cache. Magento's block cache is
 * off unless `cache_lifetime` is set — AbstractBlock::getCacheLifetime() returns null when the data
 * key is absent and _loadCache() renders straight through on null — so leaving it unset is the
 * whole protection, and setCacheLifetime() is deliberately never called here.
 *
 * Under the full page cache the block is fine: the page is cached per URL, so the URLs baked into
 * it are the URLs of the page they were baked on.
 */
class DesktopSwitcher extends Template
{
    private StoreListProvider $storeListProvider;

    private FlagSprite $flagSprite;

    public function __construct(
        Context $context,
        StoreListProvider $storeListProvider,
        FlagSprite $flagSprite,
        array $data = []
    ) {
        $this->storeListProvider = $storeListProvider;
        $this->flagSprite = $flagSprite;

        parent::__construct($context, $data);
    }

    /**
     * Symbol id for the current store, resolved here rather than in the template so that a store
     * whose region has no shipped flag still gets a symbol that exists in the sprite.
     */
    public function getCurrentFlagCode(): string
    {
        $current = $this->getCurrentStoreOption();

        return $current === null
            ? FlagSprite::FALLBACK_CODE
            : $this->flagSprite->resolve($current->getFlagCode());
    }

    /**
     * @return StoreOption[]
     */
    public function getStoreOptions(): array
    {
        return $this->storeListProvider->getOptions(true);
    }

    public function getCurrentStoreOption(): ?StoreOption
    {
        return $this->storeListProvider->getCurrentStoreOption();
    }

    /**
     * A switcher offering one choice is a label. Both renderers ask this before drawing anything.
     */
    public function isSwitchable(): bool
    {
        return count($this->getStoreOptions()) > 1;
    }
}
