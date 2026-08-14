<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Scr1be\BackInStock\Model\AlertState;

/**
 * Every write this module makes to `popup_status`, and all of them are one UPDATE.
 *
 * None of them goes through `Magento\ProductAlert\Model\Stock`. Two reasons, and the second is the
 * load-bearing one:
 *
 *  - Saving the model would rewrite every column of the row, and the row belongs to core. A mail run
 *    that is halfway through updating `send_count` on the same alert has no reason to lose that to a
 *    popup dismissal.
 *  - The state-machine plugin runs inside `Magento\ProductAlert\Model\ResourceModel\Stock::save()`.
 *    Calling `save()` again from there would re-enter the interceptor and therefore the plugin.
 *    A targeted UPDATE has no plugins on it and cannot recurse.
 */
class PopupStatusWriter
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Move one alert to a new popup state, but only from the state the caller expects it to be in.
     *
     * The `popup_status = :from` clause is what makes the transition a compare-and-set rather than a
     * blind write: two concurrent requests can both decide an alert should be queued, and only one
     * of them updates a row. The return value is how the caller finds out which one it was — the
     * push channel uses it to make sure a restock produces one notification and not two.
     *
     * @return bool True when this call is the one that performed the transition.
     */
    public function transition(int $alertId, int $from, int $to): bool
    {
        if ($alertId <= 0 || $from === $to) {
            return false;
        }

        $connection = $this->resource->getConnection();
        $affected = $connection->update(
            $this->resource->getTableName(AlertState::TABLE),
            ['popup_status' => $to],
            [
                'alert_stock_id = ?' => $alertId,
                'popup_status = ?' => $from,
            ]
        );

        return $affected > 0;
    }

    /**
     * Mark the given alerts as seen.
     *
     * The customer and website ids are part of the WHERE clause rather than something the caller is
     * trusted to have checked: the ids arrive from a browser, and an id from a browser addresses
     * somebody else's row exactly as easily as your own. Scoping the UPDATE means the worst a forged
     * id can do is update nothing.
     *
     * @param int[] $alertIds
     * @return int Number of alerts actually moved.
     */
    public function markShown(int $customerId, int $websiteId, array $alertIds): int
    {
        // `'4'` and `4` are the same row, and a browser sends both. Deduplicating here keeps the
        // `IN (...)` list the size of the popup rather than the size of whatever was posted.
        $alertIds = array_values(array_unique(array_filter(
            array_map('intval', $alertIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($customerId <= 0 || $alertIds === []) {
            return 0;
        }

        $connection = $this->resource->getConnection();

        return (int)$connection->update(
            $this->resource->getTableName(AlertState::TABLE),
            ['popup_status' => AlertState::POPUP_SHOWN],
            [
                'alert_stock_id IN (?)' => $alertIds,
                'customer_id = ?' => $customerId,
                'website_id = ?' => $websiteId,
                'popup_status = ?' => AlertState::POPUP_QUEUED,
            ]
        );
    }

    /**
     * Mark every queued alert the customer holds as seen — the popup's "dismiss all" and the bulk
     * add-to-cart both end here.
     *
     * @return int Number of alerts actually moved.
     */
    public function markAllShown(int $customerId, int $websiteId): int
    {
        if ($customerId <= 0) {
            return 0;
        }

        $connection = $this->resource->getConnection();

        return (int)$connection->update(
            $this->resource->getTableName(AlertState::TABLE),
            ['popup_status' => AlertState::POPUP_SHOWN],
            [
                'customer_id = ?' => $customerId,
                'website_id = ?' => $websiteId,
                'popup_status = ?' => AlertState::POPUP_QUEUED,
            ]
        );
    }

    /**
     * Put fired alerts back in the queue — what the CLI reset command does.
     *
     * Only rows core has already marked sent are touched. Re-queueing an alert that never fired
     * would show a popup for a product that is still out of stock, which is the one thing this
     * module must never do.
     *
     * @return int Number of alerts re-queued.
     */
    public function requeueSent(?int $customerId, ?int $websiteId): int
    {
        $connection = $this->resource->getConnection();
        $where = [
            'status = ?' => AlertState::ALERT_SENT,
            'popup_status = ?' => AlertState::POPUP_SHOWN,
        ];

        if ($customerId !== null) {
            $where['customer_id = ?'] = $customerId;
        }

        if ($websiteId !== null) {
            $where['website_id = ?'] = $websiteId;
        }

        return (int)$connection->update(
            $this->resource->getTableName(AlertState::TABLE),
            ['popup_status' => AlertState::POPUP_QUEUED],
            $where
        );
    }
}
