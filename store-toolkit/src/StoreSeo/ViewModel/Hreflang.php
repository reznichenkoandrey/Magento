<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\ViewModel;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\StoreSeo\Model\Config;
use Scr1be\StoreSeo\Model\Entity\RequestEntityResolver;
use Scr1be\StoreSeo\Model\Hreflang\AlternateLink;
use Scr1be\StoreSeo\Model\Hreflang\AlternateResolver;
use Scr1be\StoreSeo\Model\Hreflang\LocaleFormatter;
use Scr1be\StoreSeo\Model\Hreflang\XDefaultSelector;

/**
 * What the head template asks: which alternates, and which of them is x-default.
 *
 * Both answers come out of one pass — resolving alternates touches every store and, for a product
 * page, loads the product once per store — so the pass is run at most once per request and both
 * getters read the same memo.
 */
class Hreflang implements ArgumentInterface
{
    private StoreManagerInterface $storeManager;

    private StoreRepositoryInterface $storeRepository;

    private ScopeConfigInterface $scopeConfig;

    private Config $config;

    private RequestEntityResolver $entityResolver;

    private AlternateResolver $alternateResolver;

    private XDefaultSelector $xDefaultSelector;

    private LocaleFormatter $localeFormatter;

    private bool $resolved = false;

    /**
     * @var AlternateLink[]
     */
    private array $alternates = [];

    private ?AlternateLink $xDefault = null;

    public function __construct(
        StoreManagerInterface $storeManager,
        StoreRepositoryInterface $storeRepository,
        ScopeConfigInterface $scopeConfig,
        Config $config,
        RequestEntityResolver $entityResolver,
        AlternateResolver $alternateResolver,
        XDefaultSelector $xDefaultSelector,
        LocaleFormatter $localeFormatter
    ) {
        $this->storeManager = $storeManager;
        $this->storeRepository = $storeRepository;
        $this->scopeConfig = $scopeConfig;
        $this->config = $config;
        $this->entityResolver = $entityResolver;
        $this->alternateResolver = $alternateResolver;
        $this->xDefaultSelector = $xDefaultSelector;
        $this->localeFormatter = $localeFormatter;
    }

    /**
     * @return AlternateLink[]
     */
    public function getAlternates(): array
    {
        $this->resolve();

        return $this->alternates;
    }

    public function getXDefault(): ?AlternateLink
    {
        $this->resolve();

        return $this->xDefault;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;

        if (!$this->isEnabledForCurrentStore()) {
            return;
        }

        $entity = $this->entityResolver->resolve();
        if ($entity === null) {
            return;
        }

        $this->alternates = $this->alternateResolver->resolve($entity);

        if ($this->alternates === []) {
            return;
        }

        $primaryStore = $this->getPrimaryStoreId();

        $this->xDefault = $this->xDefaultSelector->select(
            $this->alternates,
            $primaryStore,
            $primaryStore === null ? null : $this->getPrimaryLanguage($primaryStore)
        );
    }

    private function isEnabledForCurrentStore(): bool
    {
        try {
            return $this->config->isHreflangEnabled((int) $this->storeManager->getStore()->getId());
        } catch (NoSuchEntityException $e) {
            return false;
        }
    }

    /**
     * Store id nominated for x-default, or null when the setting is blank or names a store that
     * has since been deleted. Both cases fall through to the selector's later rungs rather than
     * suppressing x-default, because a stale setting should not silently cost the group its default.
     */
    private function getPrimaryStoreId(): ?int
    {
        $code = $this->config->getXDefaultStoreCode();

        if ($code === null) {
            return null;
        }

        try {
            return (int) $this->storeRepository->get($code)->getId();
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    private function getPrimaryLanguage(int $storeId): ?string
    {
        $tag = $this->localeFormatter->format(
            (string) $this->scopeConfig->getValue(
                DirectoryHelper::XML_PATH_DEFAULT_LOCALE,
                ScopeInterface::SCOPE_STORE,
                $storeId
            )
        );

        return $tag === null ? null : strtolower(explode('-', $tag)[0]);
    }
}
