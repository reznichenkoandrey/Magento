<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Model;

/**
 * The two state machines this module lives between.
 *
 * `status` belongs to Magento_ProductAlert and is written in two places in core, both of which this
 * module has to react to rather than replace:
 *
 *  - `Magento\ProductAlert\Model\ResourceModel\Stock::_beforeSave()` sets it to 0 when an alert row
 *    is created, and again when a *new* model object is saved over an existing row — the
 *    re-subscribe case, where `_getAlertRow()` finds a match, merges it into the object and forces
 *    the status back to 0.
 *  - `Magento\ProductAlert\Model\Mailing\AlertProcessor::saveStockAlert()` sets it to 1 together
 *    with `send_date` and an incremented `send_count`, immediately after
 *    `ProductSalability::isSalable()` says the product is buyable again.
 *
 * `popup_status` belongs to this module and rides on the same row so that the two can never
 * disagree about which alert they describe. It is a strict three-step: an alert that has not fired
 * has nothing to show, an alert that just fired owes the customer a popup, and an alert whose popup
 * has been seen is finished until the customer subscribes again.
 */
final class AlertState
{
    /** Core: the alert is armed and waiting for the product to come back. */
    public const ALERT_ARMED = 0;

    /** Core: the back-in-stock email has gone out. */
    public const ALERT_SENT = 1;

    /** Ours: nothing to show — either the alert has not fired, or it was re-armed after showing. */
    public const POPUP_IDLE = 0;

    /** Ours: the alert fired and the customer has not seen the popup yet. */
    public const POPUP_QUEUED = 1;

    /** Ours: the popup was shown and dismissed, or the product was added to the cart from it. */
    public const POPUP_SHOWN = 2;

    /** The table both columns live on. */
    public const TABLE = 'product_alert_stock';

    private function __construct()
    {
    }
}
