<?php
declare(strict_types=1);

namespace Scr1be\GuestRegistration\Model;

/**
 * The rungs of the decision ladder, as a closed set.
 *
 * These are the module's own vocabulary rather than a boolean, because the four "no account was
 * created" cases are not the same event and a log line that cannot tell them apart is a log line
 * nobody can act on: SKIPPED_LOGGED_IN is normal traffic, SKIPPED_NO_EMAIL is a broken client,
 * LINKED_EXISTING is a returning shopper who checked out as a guest, and FAILED is an incident.
 */
enum RegistrationOutcome: string
{
    /** The module is switched off for this store view. */
    case DISABLED = 'disabled';

    /** The order already belongs to a customer — nothing to do. */
    case SKIPPED_LOGGED_IN = 'skipped_logged_in';

    /** The order carries no email address, so there is nothing to register. */
    case SKIPPED_NO_EMAIL = 'skipped_no_email';

    /** An account with that email exists, but linking to pre-existing accounts is switched off. */
    case SKIPPED_EXISTING_ACCOUNT = 'skipped_existing_account';

    /** An account with that email already existed on the website; the order was attached to it. */
    case LINKED_EXISTING = 'linked_existing';

    /** A new account was created from the order and the order was attached to it. */
    case CREATED = 'created';

    /** Something threw. The order stands; the customer simply has no account. */
    case FAILED = 'failed';

    /**
     * Whether this outcome means the shopper now has an account they did not have before.
     *
     * This is what `customer_created` on PlaceOrderOutput reports, and it is deliberately false for
     * LINKED_EXISTING: the app uses the flag to decide whether to show a "set your password" prompt,
     * and showing that to someone who already has a password is worse than showing nothing.
     */
    public function isNewAccount(): bool
    {
        return $this === self::CREATED;
    }
}
