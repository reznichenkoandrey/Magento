<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Scr1be\BackInStock\Model\Push\RestockNotifier;

/**
 * Listens for `scr1be_back_in_stock_alert_queued` and sends the notification.
 *
 * The event fires exactly once per alert, from the compare-and-set inside
 * `Scr1be\BackInStock\Plugin\PopupStateMachine` — so this observer needs no idempotency of its own,
 * and two application servers processing the same mail run cannot produce two notifications for one
 * restock.
 */
class PushOnAlertQueued implements ObserverInterface
{
    public function __construct(
        private readonly RestockNotifier $notifier
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();

        $this->notifier->notify(
            (int)$event->getData('customer_id'),
            (int)$event->getData('product_id'),
            (int)$event->getData('website_id'),
            (int)$event->getData('store_id')
        );
    }
}
