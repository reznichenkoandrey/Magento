<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Model;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;

/**
 * Decides which root category this request's menu is built from.
 *
 * Four candidates are tried in order and the first one that is an *active root category* wins:
 *
 *   1. the layout block argument — an explicit instruction from a layout handle, so nothing may
 *      override it;
 *   2. the customer group override — the group is read from the HTTP context, never from the
 *      customer session, because the HTTP context is the value the full-page cache varies on;
 *   3. the store default — the configured root category id, or the one the store view is already
 *      assigned to when that field is empty;
 *   4. the first active root category, in admin tree order.
 *
 * Every step *falls through* rather than failing. A layout argument pointing at a category that
 * was later switched off, a group mapped onto a root that was deleted, a store group whose root
 * category id is `Category::ROOT_CATEGORY_ID` (which is 0, and is what core returns for a store
 * with no group) — each of those is a configuration mistake that should cost the merchant a
 * wrong-looking menu, not a header with no navigation in it.
 *
 * Step 4 is the reason the chain terminates in something rather than nothing. It is also the only
 * step that can still answer null, and it does so only when the installation genuinely has no
 * active root category at all.
 */
class MenuResolver
{
    public function __construct(
        private readonly Config $config,
        private readonly GroupMenuMap $groupMenuMap,
        private readonly RootCategories $rootCategories,
        private readonly HttpContext $httpContext
    ) {
    }

    public function resolve(int $storeId, int $storeRootCategoryId, ?int $layoutArgument = null): ?int
    {
        foreach ($this->candidates($storeId, $storeRootCategoryId, $layoutArgument) as $candidate) {
            if ($candidate !== null && $this->rootCategories->isActiveRoot($candidate, $storeId)) {
                return $candidate;
            }
        }

        return $this->rootCategories->getFirstActiveRootId($storeId);
    }

    /**
     * True when this store view's menu can differ between customer groups.
     *
     * The block asks before deciding whether the customer group belongs in its cache key. On an
     * installation without a map — which is nearly all of them — the answer is no, and the menu
     * is cached once per store view instead of once per store view per group.
     */
    public function variesByCustomerGroup(int $storeId): bool
    {
        return !$this->groupMenuMap->isEmpty($this->config->getGroupMapRaw($storeId));
    }

    public function getCustomerGroupId(): int
    {
        return (int) $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP);
    }

    /**
     * @return array<int, int|null>
     */
    private function candidates(int $storeId, int $storeRootCategoryId, ?int $layoutArgument): array
    {
        return [
            $layoutArgument,
            $this->fromGroupMap($storeId),
            $this->config->getDefaultRootCategoryId($storeId) ?? ($storeRootCategoryId ?: null),
        ];
    }

    private function fromGroupMap(int $storeId): ?int
    {
        $map = $this->groupMenuMap->parse($this->config->getGroupMapRaw($storeId));

        return $map[$this->getCustomerGroupId()] ?? null;
    }
}
