<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Scr1be\BackInStock\Model\AlertState;

/**
 * The only two reads this module makes against `product_alert_stock`.
 *
 * Both are deliberately raw selects rather than `Magento\ProductAlert\Model\ResourceModel\Stock`
 * collections. The collection exists to hydrate alert *models* so the mail run can save them back;
 * everything downstream of here needs three scalar columns per row and then joins the interesting
 * data on from the catalogue in one product collection. Loading models for that would be one
 * `AbstractModel` per alert plus its resource, thrown away a line later.
 */
class AlertReader
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * The alerts whose popup is owed, newest first.
     *
     * `send_date` is the moment `AlertProcessor::saveStockAlert()` decided the product was salable
     * again, so ordering by it descending puts the freshest restock at the front of the popup — and
     * the tie-break on the primary key keeps the order stable when a mail run marked several alerts
     * inside the same second, which is the normal case.
     *
     * @return array<int, array{alert_stock_id: int, product_id: int, send_date: ?string}>
     */
    public function readQueued(int $customerId, int $websiteId, int $limit): array
    {
        if ($customerId <= 0 || $limit <= 0) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName(AlertState::TABLE),
                ['alert_stock_id', 'product_id', 'send_date']
            )
            ->where('customer_id = ?', $customerId)
            ->where('website_id = ?', $websiteId)
            ->where('popup_status = ?', AlertState::POPUP_QUEUED)
            ->order(['send_date DESC', 'alert_stock_id DESC'])
            ->limit($limit);

        return $this->cast($connection->fetchAll($select));
    }

    /**
     * Every stock alert the customer holds on this website, whatever state it is in.
     *
     * This is what the account page reads. It carries `status` and `popup_status` because the page's
     * whole job is to make the state machine legible — an alert that is waiting, one that fired and
     * is queued, and one that fired and was dealt with look different there.
     *
     * @return array<int, array{
     *     alert_stock_id: int, product_id: int, status: int, popup_status: int,
     *     add_date: ?string, send_date: ?string
     * }>
     */
    public function readAll(int $customerId, int $websiteId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName(AlertState::TABLE),
                ['alert_stock_id', 'product_id', 'status', 'popup_status', 'add_date', 'send_date']
            )
            ->where('customer_id = ?', $customerId)
            ->where('website_id = ?', $websiteId)
            ->order(['add_date DESC', 'alert_stock_id DESC']);

        return $this->cast($connection->fetchAll($select));
    }

    /**
     * MySQL hands back every column as a string. Casting once here means nothing downstream has to
     * remember that `popup_status` is `"1"` rather than `1` — which matters, because the identity
     * comparisons in the state machine would silently pass for a string and fail for an int.
     *
     * @param array<int, array<string, string|null>> $rows
     * @return array<int, array<string, int|string|null>>
     */
    private function cast(array $rows): array
    {
        return array_map(
            static function (array $row): array {
                foreach (['alert_stock_id', 'product_id', 'status', 'popup_status'] as $column) {
                    if (array_key_exists($column, $row)) {
                        $row[$column] = (int)$row[$column];
                    }
                }

                return $row;
            },
            $rows
        );
    }
}
