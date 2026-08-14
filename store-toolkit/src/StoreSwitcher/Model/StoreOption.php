<?php
declare(strict_types=1);

namespace Scr1be\StoreSwitcher\Model;

/**
 * One entry in the switcher, in the fields both renderers need.
 *
 * `redirectUrl` is the only field the two renderers disagree about: the desktop list has it, the
 * drawer payload does not, and that difference is the whole reason the two exist separately. See
 * Block\DrawerPayload for why.
 */
class StoreOption
{
    private int $storeId;

    private string $code;

    private string $name;

    private string $localeCode;

    private string $baseUrl;

    private ?string $redirectUrl;

    public function __construct(
        int $storeId,
        string $code,
        string $name,
        string $localeCode,
        string $baseUrl,
        ?string $redirectUrl = null
    ) {
        $this->storeId = $storeId;
        $this->code = $code;
        $this->name = $name;
        $this->localeCode = $localeCode;
        $this->baseUrl = $baseUrl;
        $this->redirectUrl = $redirectUrl;
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLocaleCode(): string
    {
        return $this->localeCode;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Null on the drawer payload, where the target URL is composed in the browser.
     */
    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    /**
     * Region subtag of the locale, lowercased — `us` out of `en_US` — which is what the flag
     * sprite is keyed by. Falls back to the language when a locale carries no region, so a store
     * configured `de` still gets a flag rather than a hole in the row.
     */
    public function getFlagCode(): string
    {
        $parts = array_values(array_filter(explode('_', $this->localeCode)));

        if ($parts === []) {
            return '';
        }

        return strtolower((string) end($parts));
    }
}
