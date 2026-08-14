<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Plugin;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Model\AbstractModel;
use Magento\ProductAlert\Model\ResourceModel\Stock as StockAlertResource;
use Scr1be\BackInStock\Model\AlertState;
use Scr1be\BackInStock\Model\ResourceModel\PopupStatusWriter;

/**
 * Keeps `popup_status` in step with the `status` column core owns.
 *
 * **Why a plugin and not an observer.** `Magento\ProductAlert\Model\Stock` sets no `_eventPrefix`,
 * so the only events its save dispatches are `model_save_after` and the generic
 * `core_abstract_save_after` — both of which fire for every model in the system and would have to be
 * filtered by `instanceof` on every save Magento makes. The resource model's `save()` is the exact
 * seam instead, and every writer reaches it: `Magento\ProductAlert\Controller\Add\Stock` and
 * `Magento\ProductAlert\Model\Mailing\AlertProcessor::saveStockAlert()` both call
 * `Magento\ProductAlert\Model\Stock::save()`, which is `AbstractModel::save()` calling
 * `$this->getResource()->save($this)` on an object the object manager built — an interceptor.
 *
 * **Why `after` and not `before` or `around`.** The interesting transition is produced *inside*
 * core's `_beforeSave()`, which runs after any `beforeSave` plugin: when a customer re-subscribes,
 * `Magento\ProductAlert\Model\ResourceModel\Stock::_beforeSave()` finds the existing row with
 * `_getAlertRow()`, merges it into the object with `addData($row)` and calls `setStatus(0)`. Before
 * that runs there is nothing to see — the object holds four ids and no status at all. `after` is the
 * first moment at which the merged row and the reset status are both visible.
 *
 * **The bug it fixes.** That merge brings the *old* `popup_status` back with it. A customer who
 * subscribed, got the email, saw the popup (`popup_status` = 2) and subscribed again ends up with an
 * alert that core has correctly re-armed (`status` = 0) and a popup state that still says the popup
 * has been shown. When the product goes out of stock and comes back, core marks the alert sent
 * again, the queue transition looks for `popup_status` = 0, does not find it, and the customer never
 * sees a popup again for that product — forever, silently, with a perfectly healthy-looking alert
 * row. Resetting the popup state whenever core re-arms the alert is the whole fix.
 *
 * **Why it is declared globally.** `etc/di.xml`, not `etc/frontend/di.xml`. The two writers live in
 * different areas — the subscribe controller is `frontend`, the mail run is `crontab` or the queue
 * consumer — and a state machine that only runs on the storefront is not a state machine.
 */
class PopupStateMachine
{
    /**
     * Dispatched once per alert, at the moment its popup is queued. Payload: `alert_id`,
     * `customer_id`, `product_id`, `website_id`, `store_id`.
     *
     * The push channel hangs off this rather than off the plugin directly, so that a build with no
     * push channel loses an observer rather than gaining a branch, and so that the notification is
     * sent exactly as often as the state actually changes.
     */
    public const EVENT_ALERT_QUEUED = 'scr1be_back_in_stock_alert_queued';

    public function __construct(
        private readonly PopupStatusWriter $writer,
        private readonly EventManager $eventManager
    ) {
    }

    /**
     * @param StockAlertResource $subject
     * @param StockAlertResource $result
     * @param AbstractModel $object
     * @return StockAlertResource
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSave(
        StockAlertResource $subject,
        StockAlertResource $result,
        AbstractModel $object
    ): StockAlertResource {
        $alertId = (int)$object->getId();

        if ($alertId <= 0) {
            return $result;
        }

        $status = (int)$object->getData('status');
        // Absent on a brand-new alert — the object was never loaded and core's merge found no row to
        // merge — and `(int) null` is POPUP_IDLE, which is exactly the right reading of "no popup
        // has ever been owed for this alert".
        $popupStatus = (int)$object->getData('popup_status');

        if ($status === AlertState::ALERT_SENT && $popupStatus === AlertState::POPUP_IDLE) {
            $this->queue($object, $alertId);

            return $result;
        }

        if ($status === AlertState::ALERT_ARMED && $popupStatus !== AlertState::POPUP_IDLE) {
            $this->rearm($object, $alertId, $popupStatus);
        }

        return $result;
    }

    /**
     * The alert just fired: owe the customer a popup, and tell anyone listening.
     */
    private function queue(AbstractModel $object, int $alertId): void
    {
        $moved = $this->writer->transition($alertId, AlertState::POPUP_IDLE, AlertState::POPUP_QUEUED);

        if (!$moved) {
            // Another process got there first. Its observer is sending the notification, so this one
            // must not send a second.
            return;
        }

        $this->sync($object, AlertState::POPUP_QUEUED);

        $this->eventManager->dispatch(
            self::EVENT_ALERT_QUEUED,
            [
                'alert_id' => $alertId,
                'customer_id' => (int)$object->getData('customer_id'),
                'product_id' => (int)$object->getData('product_id'),
                'website_id' => (int)$object->getData('website_id'),
                'store_id' => (int)$object->getData('store_id'),
            ]
        );
    }

    /**
     * Core re-armed the alert. Whatever the popup state was, it describes a subscription that is
     * over.
     */
    private function rearm(AbstractModel $object, int $alertId, int $popupStatus): void
    {
        if ($this->writer->transition($alertId, $popupStatus, AlertState::POPUP_IDLE)) {
            $this->sync($object, AlertState::POPUP_IDLE);
        }
    }

    /**
     * Bring the in-memory object back in line with the row this method just changed underneath it.
     *
     * The dirty flag has to be put back afterwards. `Magento\Framework\Model\AbstractModel::setData()`
     * raises `_hasDataChanges` whenever the value differs, and
     * `Magento\Framework\Model\ResourceModel\Db\AbstractDb::save()` had just lowered it — leaving it
     * raised would turn the next `save()` of this object from a no-op into a full row rewrite, which
     * is the opposite of what a state-machine fix should cost.
     */
    private function sync(AbstractModel $object, int $popupStatus): void
    {
        $object->setData('popup_status', $popupStatus);
        $object->setOrigData('popup_status', $popupStatus);
        $object->setHasDataChanges(false);
    }
}
