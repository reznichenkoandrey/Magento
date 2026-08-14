<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Api\Data;

/**
 * The stored shape of one carousel.
 *
 * Written as a service contract rather than a bare model because three unrelated callers read a
 * slider — the admin form, the frontend block and the widget — and a `DataObject` with magic getters
 * gives none of them a compile-time answer to "what is on this thing".
 */
interface SliderInterface
{
    public const SLIDER_ID = 'slider_id';
    public const IDENTIFIER = 'identifier';
    public const TITLE = 'title';
    public const IS_ACTIVE = 'is_active';
    public const SOURCE_TYPE = 'source_type';
    public const SOURCE_VALUE = 'source_value';
    public const PRODUCT_LIMIT = 'product_limit';
    public const SLIDES_MOBILE = 'slides_mobile';
    public const SLIDES_TABLET = 'slides_tablet';
    public const SLIDES_DESKTOP = 'slides_desktop';
    public const SLIDES_WIDE = 'slides_wide';
    public const AUTOPLAY = 'autoplay';
    public const AUTOPLAY_DELAY = 'autoplay_delay';
    public const IS_LOOP = 'is_loop';
    public const SHOW_SOCIAL_PROOF = 'show_social_proof';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /** Not a column: the store ids come from the `scr1be_slider_store` pivot. */
    public const STORE_ID = 'store_id';

    public function getSliderId(): ?int;

    public function setSliderId(?int $sliderId): self;

    public function getIdentifier(): string;

    public function setIdentifier(string $identifier): self;

    public function getTitle(): string;

    public function setTitle(string $title): self;

    public function isActive(): bool;

    public function setIsActive(bool $isActive): self;

    public function getSourceType(): string;

    public function setSourceType(string $sourceType): self;

    public function getSourceValue(): ?string;

    public function setSourceValue(?string $sourceValue): self;

    public function getProductLimit(): int;

    public function setProductLimit(int $productLimit): self;

    /**
     * Visible slide counts keyed by the breakpoint codes in {@see \Scr1be\HyvaProductSlider\Model\Breakpoints}.
     *
     * @return array<string, int>
     */
    public function getSlidesPerBreakpoint(): array;

    /**
     * @param array<string, int> $counts Keyed by breakpoint code; unknown codes are ignored.
     */
    public function setSlidesPerBreakpoint(array $counts): self;

    public function isAutoplay(): bool;

    public function setAutoplay(bool $autoplay): self;

    public function getAutoplayDelay(): int;

    public function setAutoplayDelay(int $autoplayDelay): self;

    public function isLoop(): bool;

    public function setIsLoop(bool $isLoop): self;

    public function isSocialProofEnabled(): bool;

    public function setShowSocialProof(bool $showSocialProof): self;

    /**
     * @return int[] Store ids, or [0] for "all store views".
     */
    public function getStoreIds(): array;

    /**
     * @param int[] $storeIds
     */
    public function setStoreIds(array $storeIds): self;
}
