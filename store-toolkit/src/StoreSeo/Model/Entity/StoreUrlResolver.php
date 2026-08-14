<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Entity;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

/**
 * The address one entity has in one store view.
 *
 * Built from url_rewrite rather than from the entity's own URL model, because the URL model
 * answers for the *current* store: getting a per-store answer out of it means switching the store
 * emulation for every alternate, which is a lot of state to move for a string that is already
 * sitting in a table, indexed on exactly the three columns being asked about.
 *
 * The concrete Store is type-hinted rather than StoreInterface because base URLs are not on the
 * API interface at all — the same reason core's own Magento\Store\ViewModel\SwitcherUrlProvider
 * hints the concrete class.
 */
class StoreUrlResolver
{
    /**
     * A rewrite with redirect_type 0 is the live address. Non-zero rows are the 301/302 rewrites
     * Magento keeps behind after a url_key change, and pointing an alternate at one of those would
     * advertise a redirect as if it were a page.
     */
    private const REDIRECT_TYPE_NONE = 0;

    private UrlFinderInterface $urlFinder;

    public function __construct(UrlFinderInterface $urlFinder)
    {
        $this->urlFinder = $urlFinder;
    }

    /**
     * Null when the entity has no live address in that store.
     */
    public function resolve(EntityContext $entity, Store $store): ?string
    {
        // URL_TYPE_LINK, not URL_TYPE_WEB: the link base URL is the one
        // Magento\Store\Model\Store::_updatePathUseStoreView() appends the store code to, so with
        // web/url/use_store on the alternate carries `/de/` the way a real link would.
        $baseUrl = $store->getBaseUrl(UrlInterface::URL_TYPE_LINK);

        if ($entity->getType() === EntityContext::TYPE_HOME) {
            return $baseUrl;
        }

        $rewrite = $this->urlFinder->findOneByData([
            UrlRewrite::ENTITY_TYPE => $entity->getType(),
            UrlRewrite::ENTITY_ID => $entity->getId(),
            UrlRewrite::STORE_ID => (int) $store->getId(),
            UrlRewrite::REDIRECT_TYPE => self::REDIRECT_TYPE_NONE,
        ]);

        if ($rewrite === null) {
            return null;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim((string) $rewrite->getRequestPath(), '/');
    }
}
