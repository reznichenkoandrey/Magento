<?php
declare(strict_types=1);

namespace Scr1be\AdminGridToolkit\Plugin\Sales;

use Magento\Sales\Model\AdminOrder\Create;
use Scr1be\AdminGridToolkit\Model\Config;

/**
 * Keeps an admin reorder out of core's order-edit lineage.
 *
 * AdminOrder\Create::initFromOrder() records how the create page was entered: an order *edit*
 * writes the session key `order_id`, a *reorder* writes `reordered`. Both are then read by the same
 * condition on the way to submitting the quote:
 *
 *     if ($this->getSession()->getReordered() || $this->getSession()->getOrder()->getId()) {
 *         // original_increment_id, relation_parent_id, edit_increment + 1,
 *         // increment_id = <original>-<n>
 *     }
 *
 * That branch is correct for an edit and wrong for a reorder. A reorder is a new order for the same
 * items, not a revision of the old one — but it comes out carrying the original's increment id with
 * an edit suffix, a relation_parent pointing at an order it did not replace, and an admin order
 * view that describes it as an edit. Reorder the same order twice and the second attempt computes
 * the identical "-1" suffix and dies on the unique index over (increment_id, store_id).
 *
 * Removing the flag for the duration of the call puts the reorder back on the plain path, where the
 * quote's reserved id comes from the store's order sequence.
 *
 * `around`, and this is the case around is actually for: the flag has to be absent while core runs
 * and present again afterwards, which is a scoped mutation with a restore, not a decision about
 * arguments or return values. A `before` plugin could unset it but could never put it back.
 *
 * Putting it back matters. The flag has three other readers, and each one has to keep working:
 *
 * - Order\Create::_getAclResource() maps the `save` action to Magento_Sales::reorder while the flag
 *   is set, and to Magento_Sales::create otherwise. Authorization is resolved in dispatch(), long
 *   before this plugin runs, so this request is unaffected — but a save that throws (a declined
 *   payment, a validation error) leaves the admin on the create page to try again, and that retry
 *   is a fresh request that resolves the ACL again. A role granted reorder but not create would be
 *   locked out of finishing its own reorder.
 * - Order\Create\Cancel reads it to send the admin back to the order they came from.
 * - The vault and PayPal admin blocks read it to find the customer behind the payment form.
 *
 * On the successful path the restore is immediately irrelevant — Order\Create\Save calls
 * clearStorage() on the next line — which is the point: the plugin narrows its effect to core's
 * submit and leaves the session exactly as it found it.
 */
class MintFreshIncrementIdOnReorder
{
    /**
     * Written by initFromOrder() for a reorder. Core reads it through the session's magic accessor;
     * the key is the contract, so it is read and written by name here.
     */
    private const SESSION_KEY_REORDERED = 'reordered';

    /**
     * Written by initFromOrder() for an edit.
     */
    private const SESSION_KEY_ORDER_ID = 'order_id';

    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * The session is taken from the subject rather than injected, so the object mutated here is
     * provably the one core is about to read — Create holds its session for the life of the request.
     *
     * The edit check reads the `order_id` key instead of calling getOrder()->getId() the way core
     * does. They answer the same question, and only one of them loads an order from the database in
     * order to ask it.
     *
     * @return mixed
     */
    public function aroundCreateOrder(Create $subject, callable $proceed)
    {
        if (!$this->config->isReorderIncrementIdFixEnabled()) {
            return $proceed();
        }

        $session = $subject->getSession();
        $reorderedOrderId = $session->getData(self::SESSION_KEY_REORDERED);

        // No flag, or an edit in progress: core's lineage is the correct one, leave it alone.
        if (!$reorderedOrderId || $session->getData(self::SESSION_KEY_ORDER_ID)) {
            return $proceed();
        }

        $session->unsetData(self::SESSION_KEY_REORDERED);

        try {
            return $proceed();
        } finally {
            $session->setData(self::SESSION_KEY_REORDERED, $reorderedOrderId);
        }
    }
}
