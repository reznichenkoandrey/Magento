<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Cron;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Scr1be\HyvaProductSlider\Model\Config;
use Scr1be\HyvaProductSlider\Model\ResourceModel\PurchaseIndex;

/**
 * Rebuilds the purchase index every fifteen minutes.
 *
 * The window is the *widest* one configured on any store, not the default-scope value. The index is
 * a single table shared by every store, so rebuilding it to the default scope's 30 days would
 * silently truncate a store that was configured for 90 — a bug that only shows up as a short
 * Recently Bought slider on one store view.
 */
class RefreshPurchaseIndex
{
    public function __construct(
        private readonly PurchaseIndex $purchaseIndex,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $localeDate,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $windowDays = $this->getWidestWindowDays();

        // `sales_order.created_at` is a TIMESTAMP stored in UTC, so the boundary is expressed there
        // rather than in whichever timezone the admin happens to be configured for.
        $since = $this->localeDate->date()
            ->modify(sprintf('-%d days', $windowDays))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        try {
            $rows = $this->purchaseIndex->rebuild($since);
        } catch (\Throwable $e) {
            // A failed rebuild must not stop the cron group: every other job in it is unrelated, and
            // the previous index is still serviceable — stale by one run, not wrong.
            $this->logger->error('Scr1be_HyvaProductSlider: purchase index rebuild failed.', ['exception' => $e]);

            return;
        }

        $this->logger->info(
            sprintf('Scr1be_HyvaProductSlider: purchase index rebuilt, %d rows over %d days.', $rows, $windowDays)
        );
    }

    private function getWidestWindowDays(): int
    {
        $windows = [$this->config->getPurchaseIndexWindowDays()];

        foreach ($this->storeManager->getStores() as $store) {
            $windows[] = $this->config->getPurchaseIndexWindowDays((int) $store->getId());
        }

        return max($windows);
    }
}
