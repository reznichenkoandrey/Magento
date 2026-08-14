<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Model;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Carries the validated attribution from the resolver plugin down to the submit observer.
 *
 * **Why not put it on the quote.** `Magento\Quote\Model\QuoteRepository::save()` ends with
 * `unset($this->quotesById[$quote->getId()])` — saving a quote evicts it from the repository's
 * identity map, so the next `get()` for that cart id builds a fresh model from the database.
 * Between the resolver capturing the input and `QuoteManagement::submitQuote()` running, the quote
 * is fetched and saved several times by payment and totals code. Anything set on an in-memory quote
 * and not persisted is gone by the time the order is built, and the failure is intermittent: it
 * survives on a warm path and disappears the moment another module saves the quote. Persisting it
 * instead would mean two columns on `quote` and a migration, for state whose whole lifetime is one
 * mutation.
 *
 * **Why a stack rather than a map keyed by cart.** The observer that reads this sees an order and a
 * quote; it does not see the masked cart id the mutation was addressed with, and re-deriving one
 * from the other means a `quote_id_mask` lookup on the checkout's hot path for something the caller
 * already knew. GraphQL executes mutations in a single document serially, and the `around` plugin
 * brackets exactly the window in which the observer fires, so "the attribution belonging to the
 * mutation currently running" is unambiguous — as long as the plugin pops in a `finally`. A plain
 * map would be ambiguous the moment a client sends two `placeOrder` mutations in one document.
 */
class AttributionHolder implements ResetAfterRequestInterface
{
    /**
     * @var Attribution[]
     */
    private array $stack = [];

    /**
     * Make an attribution current for the duration of one placeOrder call.
     *
     * @param Attribution $attribution
     * @return void
     */
    public function push(Attribution $attribution): void
    {
        $this->stack[] = $attribution;
    }

    /**
     * Discard the current attribution. Must be called in a `finally`.
     *
     * @return void
     */
    public function pop(): void
    {
        array_pop($this->stack);
    }

    /**
     * The attribution belonging to the placeOrder call in progress, if any.
     *
     * @return Attribution|null
     */
    public function current(): ?Attribution
    {
        $last = end($this->stack);

        return $last === false ? null : $last;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->stack = [];
    }
}
