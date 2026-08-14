<?php
declare(strict_types=1);

namespace Scr1be\StoreSwitcher\Block;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\StoreSwitcher\Model\FlagSprite;
use Scr1be\StoreSwitcher\Model\StoreListProvider;
use Scr1be\StoreSwitcher\Model\StoreOption;

/**
 * The mobile drawer's data, as JSON that says nothing about the current URL.
 *
 * The desktop list bakes the current request into every option and therefore cannot be cached.
 * This one deliberately does not: the payload holds store codes and base URLs, and the browser
 * composes the target when the visitor picks something. That makes the block's output identical on
 * every page of a store, which is what makes the cache key below safe — one entry per store and
 * scheme, reused across the whole catalogue, instead of one entry per URL (or, worse, one entry
 * reused across URLs and wrong on all but the first).
 *
 * The trade is honest: the drawer needs a few lines of JavaScript that the desktop list does not.
 * What it buys is that the drawer is also correct on pages the full page cache never sees.
 */
class DrawerPayload extends Template
{
    /**
     * A day. The payload only changes when a store is added, renamed, deactivated or has its
     * locale changed — all of them admin actions that flush the block cache anyway.
     */
    private const CACHE_LIFETIME = 86400;

    /**
     * Not named CACHE_KEY_PREFIX: AbstractBlock already declares a public constant by that name
     * (`BLOCK_`, the prefix it puts in front of the hashed key), and redeclaring it privately is a
     * fatal error rather than an override.
     */
    private const CACHE_KEY_NAMESPACE = 'SCR1BE_STORE_SWITCHER_DRAWER';

    /**
     * Not on StoreManagerInterface: `___from_store` is read straight off the request by
     * Magento\Store\Controller\Store\Redirect::execute() and has no constant anywhere in core.
     */
    private const PARAM_FROM_STORE = '___from_store';

    private StoreListProvider $storeListProvider;

    private FlagSprite $flagSprite;

    /**
     * JSON_HEX_TAG, so a store named with an angle bracket cannot close the element the payload
     * travels in. Magento\Framework\Serialize\Serializer\JsonHexTag exists for exactly this.
     */
    private JsonHexTag $json;

    public function __construct(
        Context $context,
        StoreListProvider $storeListProvider,
        FlagSprite $flagSprite,
        JsonHexTag $json,
        array $data = []
    ) {
        $this->storeListProvider = $storeListProvider;
        $this->flagSprite = $flagSprite;
        $this->json = $json;

        parent::__construct($context, $data);
    }

    public function getCurrentFlagCode(): string
    {
        $current = $this->getCurrentStoreOption();

        return $current === null
            ? FlagSprite::FALLBACK_CODE
            : $this->flagSprite->resolve($current->getFlagCode());
    }

    /**
     * Deliberately narrow: the layout name, the store and the scheme, and nothing else.
     *
     * The default from AbstractBlock is the layout name alone, which would leak one store's base
     * URLs into another's drawer. Adding the request URL — the reflex when a block is "dynamic" —
     * would defeat the point of caching it at all.
     *
     * @return string[]
     */
    public function getCacheKeyInfo()
    {
        return [
            self::CACHE_KEY_NAMESPACE,
            (string) $this->getNameInLayout(),
            (string) $this->_storeManager->getStore()->getId(),
            $this->getRequest()->isSecure() ? '1' : '0',
        ];
    }

    protected function _construct()
    {
        parent::_construct();

        $this->setData('cache_lifetime', self::CACHE_LIFETIME);
    }

    public function isSwitchable(): bool
    {
        return count($this->getStoreOptions()) > 1;
    }

    /**
     * Everything the Alpine component needs to build a redirect without a round trip.
     */
    public function getPayloadJson(): string
    {
        $current = $this->getCurrentStoreOption();

        return $this->json->serialize([
            'currentCode' => $current === null ? '' : $current->getCode(),
            'currentBaseUrl' => $current === null ? '' : $current->getBaseUrl(),
            // Built from the current store's URL builder, exactly as
            // Magento\Store\ViewModel\SwitcherUrlProvider does — the redirect controller has to run
            // in the store being left, because it is the one that knows what is being left.
            'redirectUrl' => $this->getUrl('stores/store/redirect'),
            'storeParam' => StoreManagerInterface::PARAM_NAME,
            'fromStoreParam' => self::PARAM_FROM_STORE,
            'targetUrlParam' => ActionInterface::PARAM_NAME_URL_ENCODED,
            'stores' => array_map(
                static fn (StoreOption $option): array => [
                    'code' => $option->getCode(),
                    'name' => $option->getName(),
                    'locale' => $option->getLocaleCode(),
                    'flag' => $option->getFlagCode(),
                    'baseUrl' => $option->getBaseUrl(),
                ],
                $this->getStoreOptions()
            ),
        ]);
    }

    /**
     * @return StoreOption[]
     */
    public function getStoreOptions(): array
    {
        return $this->storeListProvider->getOptions(false);
    }

    public function getCurrentStoreOption(): ?StoreOption
    {
        return $this->storeListProvider->getCurrentStoreOption();
    }
}
