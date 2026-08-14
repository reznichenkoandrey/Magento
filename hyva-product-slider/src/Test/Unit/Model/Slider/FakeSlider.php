<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Test\Unit\Model\Slider;

use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Model\Breakpoints;

/**
 * A real object rather than a mock, because the mapper is tested through a round trip: written by
 * `applyToSlider()` and read back by `toFormData()`. A mock would need every getter stubbed with the
 * value the setter was expected to receive, which is a test that asserts its own fixture.
 *
 * The concrete `Model\Slider` cannot stand in here: it extends `AbstractModel`, whose constructor
 * pulls in a context, a registry and a resource — none of which the mapper touches.
 */
class FakeSlider implements SliderInterface
{
    private ?int $sliderId = null;
    private string $identifier = '';
    private string $title = '';
    private bool $isActive = false;
    private string $sourceType = '';
    private ?string $sourceValue = null;
    private int $productLimit = 0;
    private bool $autoplay = false;
    private int $autoplayDelay = 0;
    private bool $isLoop = false;
    private bool $showSocialProof = false;

    /** @var int[] */
    private array $storeIds = [];

    /** @var array<string, int> */
    private array $slides = [
        Breakpoints::MOBILE => 1,
        Breakpoints::TABLET => 2,
        Breakpoints::DESKTOP => 4,
        Breakpoints::WIDE => 5,
    ];

    public function getSliderId(): ?int
    {
        return $this->sliderId;
    }

    public function setSliderId(?int $sliderId): SliderInterface
    {
        $this->sliderId = $sliderId;

        return $this;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): SliderInterface
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): SliderInterface
    {
        $this->title = $title;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): SliderInterface
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function setSourceType(string $sourceType): SliderInterface
    {
        $this->sourceType = $sourceType;

        return $this;
    }

    public function getSourceValue(): ?string
    {
        return $this->sourceValue;
    }

    public function setSourceValue(?string $sourceValue): SliderInterface
    {
        $this->sourceValue = $sourceValue;

        return $this;
    }

    public function getProductLimit(): int
    {
        return $this->productLimit;
    }

    public function setProductLimit(int $productLimit): SliderInterface
    {
        $this->productLimit = $productLimit;

        return $this;
    }

    /**
     * @return array<string, int>
     */
    public function getSlidesPerBreakpoint(): array
    {
        return $this->slides;
    }

    /**
     * @param array<string, int> $counts
     */
    public function setSlidesPerBreakpoint(array $counts): SliderInterface
    {
        foreach ($counts as $code => $count) {
            if (isset($this->slides[$code])) {
                $this->slides[$code] = (int) $count;
            }
        }

        return $this;
    }

    public function isAutoplay(): bool
    {
        return $this->autoplay;
    }

    public function setAutoplay(bool $autoplay): SliderInterface
    {
        $this->autoplay = $autoplay;

        return $this;
    }

    public function getAutoplayDelay(): int
    {
        return $this->autoplayDelay;
    }

    public function setAutoplayDelay(int $autoplayDelay): SliderInterface
    {
        $this->autoplayDelay = $autoplayDelay;

        return $this;
    }

    public function isLoop(): bool
    {
        return $this->isLoop;
    }

    public function setIsLoop(bool $isLoop): SliderInterface
    {
        $this->isLoop = $isLoop;

        return $this;
    }

    public function isSocialProofEnabled(): bool
    {
        return $this->showSocialProof;
    }

    public function setShowSocialProof(bool $showSocialProof): SliderInterface
    {
        $this->showSocialProof = $showSocialProof;

        return $this;
    }

    /**
     * @return int[]
     */
    public function getStoreIds(): array
    {
        return $this->storeIds;
    }

    /**
     * @param int[] $storeIds
     */
    public function setStoreIds(array $storeIds): SliderInterface
    {
        $this->storeIds = array_map('intval', $storeIds);

        return $this;
    }
}
