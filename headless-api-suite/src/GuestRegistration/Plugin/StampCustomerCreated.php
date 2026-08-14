<?php
declare(strict_types=1);

namespace Scr1be\GuestRegistration\Plugin;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Scr1be\GuestRegistration\Model\RegistrationResultHolder;

/**
 * Adds `customer_created` to the placeOrder payload.
 *
 * `after` rather than `before` or `around`: the value being added is derived from what core's
 * resolver produced, core must run unconditionally, and there is nothing to short-circuit. An
 * `around` here would be a plugin that always calls `$proceed()` — the shape people reach for out of
 * habit and then have to remember to keep exception-transparent.
 *
 * The reason this is a plugin on the resolver rather than a resolver of its own for the new field:
 * the value is not derivable from `PlaceOrderOutput`'s own data. It comes from a side effect that
 * happened three frames below, during the same request, and the only thing tying the two together is
 * the order increment id. A field resolver would receive `$value` and have no way to ask.
 *
 * The subject is typed as `ResolverInterface` rather than as the concrete resolver because two core
 * resolvers return `PlaceOrderOutput`: `PlaceOrder`, and the deprecated `SetPaymentAndPlaceOrder`
 * behind `setPaymentMethodAndPlaceOrder`. They are independent classes — the second does not
 * delegate to the first — but they produce the same `['order' => ['order_number' => ...]]` shape,
 * and the observer that fills the holder is on the quote submit event, so it runs for both. Leaving
 * the deprecated mutation returning `null` would mean the same order reports differently depending
 * on which mutation placed it.
 *
 * @see \Magento\QuoteGraphQl\Model\Resolver\PlaceOrder::resolve()
 * @see \Magento\QuoteGraphQl\Model\Resolver\SetPaymentAndPlaceOrder::resolve()
 */
class StampCustomerCreated
{
    /**
     * @param RegistrationResultHolder $resultHolder
     */
    public function __construct(private readonly RegistrationResultHolder $resultHolder)
    {
    }

    /**
     * Stamp the registration outcome onto the resolver's return value.
     *
     * Core's resolver returns one of two shapes: `['errors' => [...]]` when the order could not be
     * placed, or `['order' => [...], 'orderV2' => ..., 'errors' => []]` when it could. The first
     * shape has no order to report about, so it is passed through untouched — and the schema makes
     * `customer_created` nullable for exactly that case rather than defaulting it to false, because
     * "no order was placed" and "an order was placed and no account came of it" are different
     * answers and a mobile client will branch on them differently.
     *
     * @param ResolverInterface $subject
     * @param array|null $result
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array|null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterResolve(
        ResolverInterface $subject,
        $result,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!is_array($result)) {
            return $result;
        }

        $incrementId = $result['order']['order_number'] ?? null;
        if (!is_string($incrementId) || $incrementId === '') {
            return $result;
        }

        $outcome = $this->resultHolder->get($incrementId);
        if ($outcome === null) {
            return $result;
        }

        $result['customer_created'] = $outcome->isNewAccount();

        return $result;
    }
}
