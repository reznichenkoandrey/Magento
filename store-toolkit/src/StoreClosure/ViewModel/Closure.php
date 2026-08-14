<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\ViewModel;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\StoreClosure\Model\Banner\BannerUrlProvider;
use Scr1be\StoreClosure\Model\ClosureState;

/**
 * What the banner template asks for, resolved once.
 *
 * The banner URL is the expensive answer here — publishing hashes a file the first time after an
 * upload — so it is memoised even though a closed store renders the banner once per page.
 */
class Closure implements ArgumentInterface
{
    private ClosureState $closureState;

    private BannerUrlProvider $bannerUrlProvider;

    private StoreManagerInterface $storeManager;

    private bool $bannerResolved = false;

    private ?string $bannerUrl = null;

    public function __construct(
        ClosureState $closureState,
        BannerUrlProvider $bannerUrlProvider,
        StoreManagerInterface $storeManager
    ) {
        $this->closureState = $closureState;
        $this->bannerUrlProvider = $bannerUrlProvider;
        $this->storeManager = $storeManager;
    }

    public function isClosed(): bool
    {
        return $this->closureState->isCurrentStoreClosed();
    }

    public function getHeadline(): string
    {
        return $this->closureState->getHeadline($this->getCurrentStoreId());
    }

    public function getMessage(): string
    {
        return $this->closureState->getMessage($this->getCurrentStoreId());
    }

    /**
     * Null when no banner is configured, or when the configured one is missing from the media
     * directory — a closure notice with the text intact and no picture beats a broken image.
     */
    public function getBannerUrl(): ?string
    {
        if ($this->bannerResolved) {
            return $this->bannerUrl;
        }

        $this->bannerResolved = true;

        $file = $this->closureState->getBannerFile($this->getCurrentStoreId());

        if ($file !== '') {
            $this->bannerUrl = $this->bannerUrlProvider->getUrl($file);
        }

        return $this->bannerUrl;
    }

    private function getCurrentStoreId(): ?int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }
}
