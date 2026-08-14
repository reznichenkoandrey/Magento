<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Scr1be\OrderAttribution\Model\AttributionHolder;
use Scr1be\OrderAttribution\Model\OrderAttributionFields;

/**
 * Writes the two soft-reference columns onto the order being built.
 *
 * Bound to `sales_model_service_quote_submit_before`, not `..._success`, and the difference matters.
 * `Magento\Quote\Model\QuoteManagement::submitQuote()` dispatches the *before* event after the order
 * object has been assembled and validated but before `orderManagement->place($order)` runs, so
 * anything set here is persisted by the same insert that persists the order. The *success* event
 * fires after placement, where the same two values would cost a second `save()` on the checkout's
 * critical path — and a second save is a second set of `sales_order_save_after` observers, on an
 * order that other modules have already been told is finished.
 *
 * Nothing thrown here may escape. Attribution is analytics; a checkout that fails because a reporting
 * column could not be written is a worse outcome than a report with a gap in it.
 */
class StampOrderAttribution implements ObserverInterface
{
    /**
     * @param AttributionHolder $holder
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AttributionHolder $holder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $attribution = $this->holder->current();
            if ($attribution === null) {
                return;
            }

            // Two checks, both load-bearing. OrderInterface says it is the right entity; DataObject
            // says it can carry a column that is not on the service contract, which is exactly what a
            // soft-reference column added by a third-party module is. `QuoteManagement` builds the
            // order through `Magento\Sales\Api\Data\OrderInterfaceFactory`, whose preference is
            // `Magento\Sales\Model\Order`, so both hold — but asking for the two capabilities rather
            // than for that class keeps the observer working if a project preferences its own.
            $order = $observer->getEvent()->getData('order');
            if (!$order instanceof OrderInterface || !$order instanceof DataObject) {
                return;
            }

            $order->setData(OrderAttributionFields::SOURCE_CODE, $attribution->sourceCode);
            $order->setData(OrderAttributionFields::SOURCE_DETAIL, $attribution->detail);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Scr1be_OrderAttribution: could not stamp attribution onto the order: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
