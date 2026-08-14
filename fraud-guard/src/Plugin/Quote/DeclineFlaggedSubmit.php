<?php
declare(strict_types=1);

namespace Scr1be\FraudGuard\Plugin\Quote;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Scr1be\FraudGuard\Model\PlaceOrderGuard;

/**
 * The second choke point, for callers that skip placeOrder() entirely: the admin order-create
 * flow, a subscription/recurring-order module, an ERP importer, a data-migration script.
 *
 * On a normal storefront checkout this plugin *does* also run — QuoteManagement::placeOrder()
 * reaches submit() through $this->submit(), and $this-> dispatches against the generated
 * interceptor instance, which overrides every public non-final method
 * (Interception/Code/Generator/Interceptor.php::_getClassMethods()). Same-class calls therefore
 * re-enter the plugin chain; the widespread belief that they bypass it is wrong.
 *
 * That re-entry is harmless by construction. For a flagged customer DeclineFlaggedPlaceOrder
 * has already thrown before placeOrder()'s body — and with it the inner submit() — is ever
 * reached, so exactly one attempt is logged. For everyone else the guard is a memoized no-op.
 *
 * Those direct callers are also why PlaceOrderGuard carries an area check. Admin order creation
 * lands here, and a merchant placing an order by hand is not the threat.
 */
class DeclineFlaggedSubmit
{
    public function __construct(
        private readonly PlaceOrderGuard $guard
    ) {
    }

    /**
     * @param array $orderData
     * @throws \Magento\Payment\Gateway\Command\CommandException
     */
    public function beforeSubmit(QuoteManagement $subject, Quote $quote, $orderData = []): void
    {
        $this->guard->assertNotFlagged($quote);
    }
}
