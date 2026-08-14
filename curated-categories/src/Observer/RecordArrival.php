<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Observer;

use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Scr1be\CuratedCategories\Api\CurationEngineInterface;
use Scr1be\CuratedCategories\Model\CurationLog;
use Scr1be\CuratedCategories\Model\ResourceModel\ArrivalIndex;
use Scr1be\CuratedCategories\Model\Source\NewArrivals;

/**
 * Stamps a product's first-ever in-stock moment and puts it on the New page in the same request.
 *
 * ## Why the stock item and not the product
 *
 * A product is created long before it is buyable. It is imported with zero stock, priced, described,
 * photographed and only then switched on — usually by an ERP push that touches the stock item and
 * nothing else. `catalog_product_save_after` would fire for every one of those steps and for none of
 * the ones that matter, whereas the stock item's own save is the exact moment the answer to "can
 * anyone buy this" changes.
 *
 * The event is `cataloginventory_stock_item_save_commit_after`, which
 * `Magento\Framework\Model\AbstractModel::afterCommitCallback()` dispatches as
 * `<_eventPrefix>_save_commit_after` with `<_eventObject>` as the payload key —
 * `Magento\CatalogInventory\Model\Stock\Item` declares those as `cataloginventory_stock_item` and
 * `item`. Commit-after rather than save-after for the usual reason: the row is durable, so anything
 * this observer gets wrong cannot roll back the save that triggered it.
 *
 * ## Why only the first arrival does anything
 *
 * Every stock write reaches this observer, including the one an order placement makes when it
 * decrements quantity. After the first arrival is stamped, the product's membership is the hourly
 * reconcile's business — it knows about the window and the exclusion rules and does not need an
 * event to tell it a product still exists. Doing the work here every time would put a category
 * membership read on the checkout path.
 */
class RecordArrival implements ObserverInterface
{
    public function __construct(
        private readonly NewArrivals $newArrivals,
        private readonly CurationEngineInterface $engine,
        private readonly ArrivalIndex $arrivalIndex,
        private readonly DateTime $dateTime,
        private readonly CurationLog $log
    ) {
    }

    public function execute(Observer $observer): void
    {
        $item = $observer->getEvent()->getData('item');

        if (!$item instanceof StockItemInterface || !$item->getIsInStock()) {
            return;
        }

        $productId = (int) $item->getProductId();

        if ($productId <= 0) {
            return;
        }

        try {
            if ($this->arrivalIndex->getArrivalDate($productId) !== null) {
                return;
            }

            // `arrived_at` is a TIMESTAMP column, so the value goes in as UTC — the same clock the
            // window boundary is converted into before it is compared against it.
            $this->arrivalIndex->recordArrival($productId, $this->dateTime->gmtDate());

            $this->addToNewArrivals($productId);
        } catch (\Throwable $exception) {
            // A commit-after observer that throws turns a completed stock save into an error page,
            // and on the ERP path into a failed API call for a write that succeeded. The arrival is
            // either recorded or it is not; the hourly reconcile is the safety net either way.
            $this->log->logFailure(NewArrivals::CODE, $exception);
        }
    }

    private function addToNewArrivals(int $productId): void
    {
        if (!$this->newArrivals->isEnabled()) {
            return;
        }

        $target = $this->newArrivals->getTarget();

        if ($target === null || !$this->newArrivals->qualifies($productId)) {
            return;
        }

        $this->log->logResult($this->engine->add($target, [$productId]));
    }
}
