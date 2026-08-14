<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Plugin;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\QuoteGraphQl\Model\Resolver\PlaceOrder;
use Scr1be\OrderAttribution\Model\AttributionHolder;
use Scr1be\OrderAttribution\Model\SourceValidator;
use Scr1be\OrderAttribution\Model\UnknownSourceException;

/**
 * Validates `input.order_source` and keeps it current for the duration of the mutation.
 *
 * `around`, and this is the one place in the suite where that is the right answer rather than a
 * habit. The plugin needs to do three things that no other type can do together:
 *
 *  - refuse before core runs, when the source is unknown. A `before` could do that.
 *  - hold state across core's execution, because the observer that consumes it fires *inside*
 *    `$proceed()`. A `before` cannot; an `after` runs too late.
 *  - clean that state up whether core returns or throws. Only a `try`/`finally` around `$proceed()`
 *    gives that, and leaking an attribution into a later mutation would attribute somebody else's
 *    order.
 *
 * The deprecated `setPaymentMethodAndPlaceOrder` mutation is deliberately not covered.
 * `Magento\QuoteGraphQl\Model\Resolver\SetPaymentAndPlaceOrder` is an independent resolver — it does
 * not delegate to this one, it holds its own `Magento\QuoteGraphQl\Model\Cart\SetPaymentAndPlaceOrder`
 * model — so a plugin here never sees it. Rather than add a second input field to a mutation core
 * marked `@deprecated`, this module leaves it alone: an order placed that way simply carries no
 * attribution, which is the same thing that happens to a storefront order.
 *
 * @see \Magento\QuoteGraphQl\Model\Resolver\PlaceOrder::resolve()
 */
class CaptureAttribution
{
    /**
     * @param SourceValidator $validator
     * @param AttributionHolder $holder
     */
    public function __construct(
        private readonly SourceValidator $validator,
        private readonly AttributionHolder $holder
    ) {
    }

    /**
     * @param PlaceOrder $subject
     * @param callable $proceed
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return mixed
     * @throws UnknownSourceException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundResolve(
        PlaceOrder $subject,
        callable $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $input = $args['input']['order_source'] ?? null;
        $attribution = $this->validator->validate(is_array($input) ? $input : null);

        if ($attribution === null) {
            return $proceed($field, $context, $info, $value, $args);
        }

        $this->holder->push($attribution);

        try {
            return $proceed($field, $context, $info, $value, $args);
        } finally {
            $this->holder->pop();
        }
    }
}
