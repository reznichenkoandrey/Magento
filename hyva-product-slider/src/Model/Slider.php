<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model;

use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Model\AbstractModel;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Model\ResourceModel\Slider as SliderResource;

/**
 * The entity. Typed accessors over the `DataObject` bag, and one cache tag.
 *
 * `IdentityInterface` is the reason a merchandiser can change a slider and see it on the storefront
 * without flushing anything, and it works through two different mechanisms that are easy to conflate:
 *
 * - `AbstractModel::afterSave()` calls `cleanModelCache()`, which cleans by `getCacheTags()` — the
 *   `$_cacheTag` property below, not `getIdentities()`. That is what reaches the block cache.
 * - The same `afterSave()` dispatches `clean_cache_by_tags`, which `Magento\PageCache\Observer\
 *   FlushCacheByTags` turns into a full-page-cache clean using tags from `Cache\Tag\Strategy\
 *   Identifier` — and *that* strategy is the one that reads `getIdentities()`.
 *
 * The frontend block declares both tags for exactly this reason.
 */
class Slider extends AbstractModel implements SliderInterface, IdentityInterface
{
    public const CACHE_TAG = 'scr1be_slider';

    protected $_cacheTag = self::CACHE_TAG;

    protected $_eventPrefix = 'scr1be_slider';

    protected function _construct(): void
    {
        $this->_init(SliderResource::class);
    }

    /**
     * @return string[]
     */
    public function getIdentities(): array
    {
        return [self::CACHE_TAG . '_' . $this->getSliderId()];
    }

    public function getSliderId(): ?int
    {
        $value = $this->getData(self::SLIDER_ID);

        return $value === null ? null : (int) $value;
    }

    public function setSliderId(?int $sliderId): SliderInterface
    {
        return $this->setData(self::SLIDER_ID, $sliderId);
    }

    public function getIdentifier(): string
    {
        return (string) $this->getData(self::IDENTIFIER);
    }

    public function setIdentifier(string $identifier): SliderInterface
    {
        return $this->setData(self::IDENTIFIER, $identifier);
    }

    public function getTitle(): string
    {
        return (string) $this->getData(self::TITLE);
    }

    public function setTitle(string $title): SliderInterface
    {
        return $this->setData(self::TITLE, $title);
    }

    public function isActive(): bool
    {
        return (bool) $this->getData(self::IS_ACTIVE);
    }

    public function setIsActive(bool $isActive): SliderInterface
    {
        return $this->setData(self::IS_ACTIVE, $isActive ? 1 : 0);
    }

    public function getSourceType(): string
    {
        return (string) $this->getData(self::SOURCE_TYPE);
    }

    public function setSourceType(string $sourceType): SliderInterface
    {
        return $this->setData(self::SOURCE_TYPE, $sourceType);
    }

    public function getSourceValue(): ?string
    {
        $value = $this->getData(self::SOURCE_VALUE);

        return $value === null ? null : (string) $value;
    }

    public function setSourceValue(?string $sourceValue): SliderInterface
    {
        return $this->setData(self::SOURCE_VALUE, $sourceValue);
    }

    public function getProductLimit(): int
    {
        return (int) $this->getData(self::PRODUCT_LIMIT);
    }

    public function setProductLimit(int $productLimit): SliderInterface
    {
        return $this->setData(self::PRODUCT_LIMIT, $productLimit);
    }

    /**
     * @return array<string, int>
     */
    public function getSlidesPerBreakpoint(): array
    {
        return [
            Breakpoints::MOBILE => (int) $this->getData(self::SLIDES_MOBILE),
            Breakpoints::TABLET => (int) $this->getData(self::SLIDES_TABLET),
            Breakpoints::DESKTOP => (int) $this->getData(self::SLIDES_DESKTOP),
            Breakpoints::WIDE => (int) $this->getData(self::SLIDES_WIDE),
        ];
    }

    /**
     * @param array<string, int> $counts
     */
    public function setSlidesPerBreakpoint(array $counts): SliderInterface
    {
        $columns = [
            Breakpoints::MOBILE => self::SLIDES_MOBILE,
            Breakpoints::TABLET => self::SLIDES_TABLET,
            Breakpoints::DESKTOP => self::SLIDES_DESKTOP,
            Breakpoints::WIDE => self::SLIDES_WIDE,
        ];

        foreach ($counts as $code => $count) {
            if (isset($columns[$code])) {
                $this->setData($columns[$code], (int) $count);
            }
        }

        return $this;
    }

    public function isAutoplay(): bool
    {
        return (bool) $this->getData(self::AUTOPLAY);
    }

    public function setAutoplay(bool $autoplay): SliderInterface
    {
        return $this->setData(self::AUTOPLAY, $autoplay ? 1 : 0);
    }

    public function getAutoplayDelay(): int
    {
        return (int) $this->getData(self::AUTOPLAY_DELAY);
    }

    public function setAutoplayDelay(int $autoplayDelay): SliderInterface
    {
        return $this->setData(self::AUTOPLAY_DELAY, $autoplayDelay);
    }

    public function isLoop(): bool
    {
        return (bool) $this->getData(self::IS_LOOP);
    }

    public function setIsLoop(bool $isLoop): SliderInterface
    {
        return $this->setData(self::IS_LOOP, $isLoop ? 1 : 0);
    }

    public function isSocialProofEnabled(): bool
    {
        return (bool) $this->getData(self::SHOW_SOCIAL_PROOF);
    }

    public function setShowSocialProof(bool $showSocialProof): SliderInterface
    {
        return $this->setData(self::SHOW_SOCIAL_PROOF, $showSocialProof ? 1 : 0);
    }

    /**
     * @return int[]
     */
    public function getStoreIds(): array
    {
        $storeIds = $this->getData(self::STORE_ID);

        if ($storeIds === null) {
            return [];
        }

        return array_values(array_map('intval', (array) $storeIds));
    }

    /**
     * @param int[] $storeIds
     */
    public function setStoreIds(array $storeIds): SliderInterface
    {
        return $this->setData(self::STORE_ID, array_values(array_map('intval', $storeIds)));
    }
}
