<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Block;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\Store;
use Scr1be\HyvaMegaMenu\Model\Config;
use Scr1be\HyvaMegaMenu\Model\Icon\SpriteRegistry;
use Scr1be\HyvaMegaMenu\Model\MenuResolver;
use Scr1be\HyvaMegaMenu\Model\MenuTree;
use Scr1be\HyvaMegaMenu\Model\MenuTreeBuilder;

/**
 * The one block the header menu is rendered from.
 *
 * It implements `IdentityInterface` so that `Magento\PageCache\Model\Layout\LayoutPlugin`
 * collects the menu's category tags into the page's `X-Magento-Tags` header. That is also the
 * reason this block has a `cache_lifetime` in layout but deliberately no `ttl`: core's
 * `ProcessLayoutRenderElement` observer replaces the output of any block with a `ttl` with an
 * `<esi:include>` when Varnish is the page cache, and the same `LayoutPlugin` then skips that
 * block's identities. A menu inside an ESI include is a second request per page; a menu whose
 * identities were skipped is a page that never notices a category was renamed. Neither is worth
 * the fragment-level TTL.
 *
 * The tree is built lazily and once. `getIdentities()` is called after rendering, and on a
 * block_html cache hit the template never runs — so on that path the identities are what triggers
 * the build. That is a query the block cache does not save, and it is the right one to keep
 * paying: a menu that renders from cache but reports no cache tags would leave every page in the
 * full-page cache claiming it does not depend on the catalogue.
 */
class MegaMenu extends Template implements IdentityInterface
{
    /**
     * Layout argument: `<argument name="menu_root" xsi:type="number">5</argument>` on this block
     * pins the menu to one root category for the handle that sets it. It is the first step of the
     * resolution chain, so nothing else can override it.
     */
    private const ARGUMENT_MENU_ROOT = 'menu_root';

    private ?MenuTree $menuTree = null;

    /**
     * Resolution runs for the cache key and again for the tree, and `null` is one of its answers,
     * so the memo needs a flag of its own rather than a nullable field standing in for one.
     */
    private bool $rootCategoryResolved = false;

    private ?int $rootCategoryId = null;

    public function __construct(
        Context $context,
        private readonly MenuResolver $menuResolver,
        private readonly MenuTreeBuilder $menuTreeBuilder,
        private readonly Config $config,
        private readonly SpriteRegistry $spriteRegistry,
        private readonly JsonHexTag $jsonSerializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMenuItems(): array
    {
        return $this->getMenuTree()->items;
    }

    public function hasMenuItems(): bool
    {
        return !$this->getMenuTree()->isEmpty();
    }

    /**
     * The third level, as the JSON that goes into the island — or an empty string when the level
     * is switched off or the catalogue simply has no third level.
     *
     * `JsonHexTag` rather than plain `json_encode`: it calls `json_encode` with `JSON_HEX_TAG`, so
     * every angle bracket in the payload leaves as a `\uXXXX` escape. A category named after a
     * closing script tag therefore cannot close the data block it travels in.
     */
    public function getIslandJson(): string
    {
        $island = $this->getMenuTree()->island;

        return $island === [] ? '' : $this->jsonSerializer->serialize($island);
    }

    /**
     * @return array<string, string> sprite key => inner SVG markup
     */
    public function getSpriteSymbols(): array
    {
        return $this->spriteRegistry->getSymbolsFor($this->getMenuTree()->spriteKeys);
    }

    /**
     * @return string[]
     */
    public function getIdentities(): array
    {
        return $this->getMenuTree()->getIdentities();
    }

    /**
     * The store view and the resolved root category are what the output actually depends on, so
     * both are in the key regardless.
     *
     * The customer group joins them only when a group map exists. Without a map the menu cannot
     * differ between groups, and adding the group anyway would shard one cache entry per store
     * into one per group for no difference in output — on an installation with four groups that
     * is four renders of identical HTML, and on one with a group per key account it is a great
     * many more. With a map, the group is what created the dependency, so the key says so rather
     * than relying on the resolved root to imply it.
     *
     * @return string[]
     */
    public function getCacheKeyInfo(): array
    {
        $storeId = $this->getStoreId();

        $info = array_merge(parent::getCacheKeyInfo(), [
            'store_' . $storeId,
            'root_' . (string) ($this->resolveRootCategoryId() ?? 'none'),
        ]);

        if ($this->menuResolver->variesByCustomerGroup($storeId)) {
            $info[] = 'group_' . $this->menuResolver->getCustomerGroupId();
        }

        return $info;
    }

    private function getMenuTree(): MenuTree
    {
        if ($this->menuTree !== null) {
            return $this->menuTree;
        }

        $rootCategoryId = $this->resolveRootCategoryId();

        if ($rootCategoryId === null) {
            return $this->menuTree = MenuTree::empty();
        }

        $storeId = $this->getStoreId();

        return $this->menuTree = $this->menuTreeBuilder->build(
            $rootCategoryId,
            $storeId,
            $this->config->isThirdLevelEnabled($storeId)
        );
    }

    /**
     * `getRootCategoryId()` lives on the concrete `Store`, not on `StoreInterface`, so the type
     * is checked rather than assumed. A store implementation that does not expose one contributes
     * nothing to the chain and resolution carries on at the next step.
     */
    private function resolveRootCategoryId(): ?int
    {
        if ($this->rootCategoryResolved) {
            return $this->rootCategoryId;
        }

        $store = $this->_storeManager->getStore();
        $this->rootCategoryResolved = true;

        return $this->rootCategoryId = $this->menuResolver->resolve(
            (int) $store->getId(),
            $store instanceof Store ? (int) $store->getRootCategoryId() : 0,
            $this->getLayoutMenuRoot()
        );
    }

    /**
     * The argument is admin-authored layout XML rather than request input, but it is still read
     * defensively: a typo that produced `menu_root="women"` should fall through the resolution
     * chain to the store default, not cast itself to category 0.
     */
    private function getLayoutMenuRoot(): ?int
    {
        $argument = $this->getData(self::ARGUMENT_MENU_ROOT);

        if (is_int($argument)) {
            return $argument > 0 ? $argument : null;
        }

        return is_string($argument) && ctype_digit($argument) && (int) $argument > 0
            ? (int) $argument
            : null;
    }

    private function getStoreId(): int
    {
        return (int) $this->_storeManager->getStore()->getId();
    }
}
