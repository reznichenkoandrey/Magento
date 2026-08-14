<?php
declare(strict_types=1);

namespace Scr1be\GuestRegistration\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Scr1be\GuestRegistration\Model\GuestRegistrar;
use Scr1be\GuestRegistration\Model\RegistrationResultHolder;

/**
 * Runs the ladder when a quote has become an order, and parks the verdict for the resolver plugin.
 *
 * Wired in `etc/graphql/events.xml` and nowhere else. `sales_model_service_quote_submit_success` is
 * dispatched by `Magento\Quote\Model\QuoteManagement::submitQuote()` with `['order' => $order,
 * 'quote' => $quote]`, and it fires for every checkout on the installation — storefront, REST, admin
 * "create order", and GraphQL. Only the last of those is this module's business: the storefront has
 * its own "create an account" checkbox, and an admin placing an order on the phone has not been
 * asked whether the caller wants an account. Scoping the observer to the graphql area rather than
 * putting an `if` inside it means the other three code paths never even load the class.
 *
 * The event is dispatched *after* `orderManagement->place()` has returned, so the order exists and
 * has an id — which is what `OrderCustomerManagementInterface::create()` needs.
 */
class RegisterGuestAfterSubmit implements ObserverInterface
{
    /**
     * @param GuestRegistrar $registrar
     * @param RegistrationResultHolder $resultHolder
     */
    public function __construct(
        private readonly GuestRegistrar $registrar,
        private readonly RegistrationResultHolder $resultHolder
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface) {
            return;
        }

        $incrementId = (string)$order->getIncrementId();
        if ($incrementId === '') {
            return;
        }

        $this->resultHolder->record($incrementId, $this->registrar->register($order));
    }
}
