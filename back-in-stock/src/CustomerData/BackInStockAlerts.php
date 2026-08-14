<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\CustomerData;

use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Scr1be\BackInStock\Model\AlertItemFormatter;
use Scr1be\BackInStock\Model\AlertItemProvider;
use Scr1be\BackInStock\Model\Config;
use Scr1be\BackInStock\Model\StorefrontScope;

/**
 * The `scr1be-back-in-stock` customer-data section.
 *
 * **Why a section rather than a block.** The popup is per-customer content on pages the full page
 * cache is serving to everybody. A block would either punch a hole in the cache or render the
 * previous visitor's alerts to the next one. Customer data is the mechanism Magento already has for
 * exactly this, and it is what Hyvä's storefront listens to.
 *
 * **When it is refetched.** Hyvä's private-content bootstrap
 * (`Hyva_Theme::page/js/private-content.phtml`) calls `customer/section/load` with an empty
 * `sections` parameter, and `Magento\Customer\Controller\Section\Load::execute()` reads an empty
 * parameter as `null` and returns *every* section — so, unlike Luma, Hyvä has no per-section
 * invalidation map and this module ships no `sections.xml`. What triggers a refetch is the
 * `private_content_version` cookie changing, which `Magento\Framework\App\PageCache\Version::process()`
 * does on every POST request, plus the storage TTL, plus an explicit
 * `reload-customer-section-data` event — which is the one the popup fires after a dismissal.
 *
 * **The consequence, stated plainly.** A restock that happens while the customer is away is picked
 * up on their next POST, on their next visit after the cached section expires, or on their next
 * login — not within seconds. That is the honest ceiling of a section-based surface, and it is why
 * the push channel exists.
 */
class BackInStockAlerts implements SectionSourceInterface
{
    public function __construct(
        private readonly AlertItemProvider $provider,
        private readonly AlertItemFormatter $formatter,
        private readonly StorefrontScope $storefrontScope,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getSectionData()
    {
        try {
            $scope = $this->storefrontScope->current();
        } catch (NoSuchEntityException $exception) {
            return $this->empty();
        }

        if (!$scope->isIdentified() || !$this->config->isPopupEnabled($scope->storeId)) {
            return $this->empty();
        }

        try {
            $items = array_map(
                fn ($item): array => $this->formatter->toArray($item, $scope->storeId),
                $this->provider->getQueued($scope)
            );
        } catch (\Exception $exception) {
            // Every section on the page is assembled by one controller call. An exception thrown
            // here does not degrade the popup — it turns the whole customer-data response into a
            // 400, which empties the minicart, the wishlist counter and the welcome message on every
            // page of the site. An empty section is the only acceptable failure mode.
            $this->logger->error(
                'Back-in-stock section could not be built: ' . $exception->getMessage(),
                ['exception' => $exception]
            );

            return $this->empty();
        }

        return [
            'count' => count($items),
            'items' => array_values($items),
        ];
    }

    /**
     * @return array{count: int, items: array}
     */
    private function empty(): array
    {
        return ['count' => 0, 'items' => []];
    }
}
