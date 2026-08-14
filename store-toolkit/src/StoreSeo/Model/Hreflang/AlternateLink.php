<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Model\Hreflang;

/**
 * One `<link rel="alternate" hreflang="…" href="…">`, plus the store it came from so the x-default
 * selector can reason about it.
 */
class AlternateLink
{
    private int $storeId;

    private string $hreflang;

    private string $href;

    public function __construct(int $storeId, string $hreflang, string $href)
    {
        $this->storeId = $storeId;
        $this->hreflang = $hreflang;
        $this->href = $href;
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    public function getHreflang(): string
    {
        return $this->hreflang;
    }

    public function getHref(): string
    {
        return $this->href;
    }

    /**
     * The language subtag on its own — `en` out of `en-GB`.
     *
     * The x-default ladder's middle rung compares languages, not full tags: when the nominated
     * primary store is missing from this page's group, an `en-GB` alternate is a far better
     * stand-in for an `en-US` primary than an unrelated locale that happens to sort first.
     */
    public function getLanguage(): string
    {
        return strtolower(explode('-', $this->hreflang)[0]);
    }
}
