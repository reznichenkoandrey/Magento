<?php
declare(strict_types=1);

namespace Scr1be\StoreSwitcher\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Scr1be\StoreSwitcher\Model\FlagSprite;
use Scr1be\StoreSwitcher\Model\StoreListProvider;
use Scr1be\StoreSwitcher\Model\StoreOption;

/**
 * Emits the `<symbol>` definitions both switchers reference.
 *
 * Once per page, near the top of the body, so a `<use>` further down resolves. Cached on the same
 * URL-free key as the drawer payload for the same reason: the symbol set depends on which stores
 * exist, not on which page is being looked at.
 */
class FlagSpriteBlock extends Template
{
    private const CACHE_LIFETIME = 86400;

    /**
     * See DrawerPayload: AbstractBlock owns the name CACHE_KEY_PREFIX.
     */
    private const CACHE_KEY_NAMESPACE = 'SCR1BE_STORE_SWITCHER_FLAGS';

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
     * @return string[]
     */
    public function getCacheKeyInfo()
    {
        return [
            self::CACHE_KEY_NAMESPACE,
            (string) $this->getNameInLayout(),
            (string) $this->_storeManager->getStore()->getId(),
        ];
    }

    protected function _construct()
    {
        parent::_construct();

        $this->setData('cache_lifetime', self::CACHE_LIFETIME);
    }

    /**
     * @return array<string, array{orientation: string, colors: string[]}>
     */
    public function getUsedFlags(): array
    {
        return $this->flagSprite->getUsedFlags(
            array_map(
                static fn (StoreOption $option): string => $option->getFlagCode(),
                $this->storeListProvider->getOptions(false)
            )
        );
    }

    public function getFallbackCode(): string
    {
        return FlagSprite::FALLBACK_CODE;
    }

    public function isVertical(string $orientation): bool
    {
        return $orientation === FlagSprite::ORIENTATION_VERTICAL;
    }
}
