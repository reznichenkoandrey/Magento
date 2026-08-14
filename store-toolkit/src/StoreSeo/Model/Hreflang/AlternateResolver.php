<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Hreflang;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\StoreSeo\Model\Entity\EntityContext;
use Scr1be\StoreSeo\Model\Entity\StoreAvailability\CheckerPool;
use Scr1be\StoreSeo\Model\Entity\StoreUrlResolver;

/**
 * Turns "the current request is about entity X" into the set of alternates worth advertising.
 *
 * Three gates, in cost order: the store has to be active, the entity has to be available there,
 * and it has to have a live URL there. Each one removes work from the next.
 */
class AlternateResolver
{
    /**
     * A single-locale group is not a group. If every alternate that survived the gates carries the
     * same hreflang value — one store, or five stores all configured `en_US` — the markup says
     * nothing a crawler can act on, so none is emitted at all.
     */
    private const MINIMUM_DISTINCT_LOCALES = 2;

    private StoreManagerInterface $storeManager;

    private ScopeConfigInterface $scopeConfig;

    private CheckerPool $checkerPool;

    private StoreUrlResolver $urlResolver;

    private LocaleFormatter $localeFormatter;

    public function __construct(
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        CheckerPool $checkerPool,
        StoreUrlResolver $urlResolver,
        LocaleFormatter $localeFormatter
    ) {
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->checkerPool = $checkerPool;
        $this->urlResolver = $urlResolver;
        $this->localeFormatter = $localeFormatter;
    }

    /**
     * @return AlternateLink[] Empty when the page does not deserve an hreflang group.
     */
    public function resolve(EntityContext $entity): array
    {
        $checker = $this->checkerPool->get($entity->getType());

        if ($checker === null) {
            return [];
        }

        $links = [];
        $seenLocales = [];

        foreach ($this->storeManager->getStores() as $store) {
            // Base URLs live on the concrete Store, not on StoreInterface; anything else in the
            // list cannot be turned into an href, so it is skipped rather than crashed on.
            if (!$store instanceof Store || !$store->isActive()) {
                continue;
            }

            $storeId = (int) $store->getId();

            $hreflang = $this->localeFormatter->format($this->getLocaleCode($store));
            if ($hreflang === null) {
                continue;
            }

            // First store to claim a locale keeps it. Two stores on one locale is a legitimate
            // setup (a B2B and a B2C store view sharing a language) but only one of them can be
            // the answer to "the en-GB version of this page", and a duplicated hreflang value
            // invalidates the whole group.
            if (isset($seenLocales[$hreflang])) {
                continue;
            }

            if (!$checker->isAvailable($entity->getId(), $storeId)) {
                continue;
            }

            $href = $this->urlResolver->resolve($entity, $store);
            if ($href === null) {
                continue;
            }

            $seenLocales[$hreflang] = true;
            $links[] = new AlternateLink($storeId, $hreflang, $href);
        }

        return count($links) >= self::MINIMUM_DISTINCT_LOCALES ? $links : [];
    }

    private function getLocaleCode(Store $store): ?string
    {
        $locale = $this->scopeConfig->getValue(
            DirectoryHelper::XML_PATH_DEFAULT_LOCALE,
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        );

        return $locale === null ? null : (string) $locale;
    }
}
