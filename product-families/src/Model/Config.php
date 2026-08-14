<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Every configuration read in the module, in one place, already clamped.
 *
 * Two scopes are in play and they are not interchangeable. `catalog_product_link` has no store
 * column — a link either exists or it does not — so everything the reconcile reads is default-scope
 * only and the system.xml offers no store switcher for it. The one exception is the row heading,
 * which is text on a page and therefore store-view scoped like any other storefront string.
 */
class Config
{
    private const XML_PATH_ENABLED = 'scr1be_product_families/general/enabled';
    private const XML_PATH_CRON_ENABLED = 'scr1be_product_families/general/cron_enabled';
    private const XML_PATH_CRON_DRY_RUN = 'scr1be_product_families/general/cron_dry_run';

    private const FAMILY_PATH = 'scr1be_product_families/%s/%s';

    /**
     * A family row on a product page is a row of chips, not a catalogue. Twelve is what fits two
     * lines on a phone before the row starts scrolling past the fold.
     */
    public const DEFAULT_MAX_MEMBERS = 12;

    private const MIN_MEMBERS = 1;

    /**
     * The ceiling is a guard on the write volume rather than a design opinion: every member of a
     * family links to this many others, so the row count grows with the product of the two.
     */
    private const MAX_MEMBERS = 50;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    public function isCronEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CRON_ENABLED);
    }

    /**
     * The dry-run gate exists so a scheduled reconcile can be switched on in a live installation and
     * watched in the log for a night before it is allowed to write anything.
     */
    public function isCronDryRun(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CRON_DRY_RUN);
    }

    public function isFamilyEnabled(string $familyCode): bool
    {
        return $this->scopeConfig->isSetFlag($this->path($familyCode, 'enabled'));
    }

    public function getGroupAttribute(string $familyCode): string
    {
        return trim((string)$this->scopeConfig->getValue($this->path($familyCode, 'group_attribute')));
    }

    public function getVariantAttribute(string $familyCode): string
    {
        return trim((string)$this->scopeConfig->getValue($this->path($familyCode, 'variant_attribute')));
    }

    /**
     * Out-of-range values fall back to the default rather than to the nearest bound. A merchant who
     * typed 0 meant "off" and should switch the family off; silently reading it as 1 would leave a
     * one-chip row on every product page and look like a bug in the module.
     */
    public function getMaxMembers(string $familyCode): int
    {
        $configured = (int)$this->scopeConfig->getValue($this->path($familyCode, 'max_members'));

        return $configured >= self::MIN_MEMBERS && $configured <= self::MAX_MEMBERS
            ? $configured
            : self::DEFAULT_MAX_MEMBERS;
    }

    public function isDistinctVariants(string $familyCode): bool
    {
        return $this->scopeConfig->isSetFlag($this->path($familyCode, 'distinct_variants'));
    }

    public function getLabel(string $familyCode, ?int $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            $this->path($familyCode, 'label'),
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    private function path(string $familyCode, string $field): string
    {
        return sprintf(self::FAMILY_PATH, $familyCode, $field);
    }
}
