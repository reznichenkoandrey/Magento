<?php
declare(strict_types=1);

namespace Scr1be\GuestRegistration\Model;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Carries the observer's verdict across to the resolver plugin.
 *
 * The observer that does the work runs deep inside `QuoteManagement::submitQuote()`, several frames
 * below the resolver whose return value has to report the outcome, and there is no argument or
 * return value threading the two together. A request-scoped singleton is the seam.
 *
 * It implements ResetAfterRequestInterface for the same reason `Magento\QuoteGraphQl\Model\Resolver\
 * PlaceOrder` does (that class implements it to clear its own `$errors` array): under a persistent
 * application server the object manager is not rebuilt between requests, so any singleton holding
 * per-request state has to say how it is emptied. Without it, request N+1 would inherit request N's
 * verdict — and the failure mode is a shopper being told an account was created for them when it
 * was created for somebody else.
 *
 * Keyed by order increment id rather than kept as a single slot, because one GraphQL request may
 * legitimately place more than one order (aliased mutations in a single document), and a single
 * slot would report the last one for all of them.
 */
class RegistrationResultHolder implements ResetAfterRequestInterface
{
    /**
     * @var array<string, RegistrationOutcome>
     */
    private array $outcomes = [];

    /**
     * Record the ladder's verdict for one order.
     *
     * @param string $orderIncrementId
     * @param RegistrationOutcome $outcome
     * @return void
     */
    public function record(string $orderIncrementId, RegistrationOutcome $outcome): void
    {
        if ($orderIncrementId === '') {
            return;
        }

        $this->outcomes[$orderIncrementId] = $outcome;
    }

    /**
     * Read the verdict for one order.
     *
     * Returns null when this request placed no such order — which is the honest answer for a
     * resolver plugin asked about an order the observer never saw.
     *
     * @param string $orderIncrementId
     * @return RegistrationOutcome|null
     */
    public function get(string $orderIncrementId): ?RegistrationOutcome
    {
        return $this->outcomes[$orderIncrementId] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->outcomes = [];
    }
}
