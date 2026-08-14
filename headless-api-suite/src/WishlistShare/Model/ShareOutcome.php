<?php
declare(strict_types=1);

namespace Scr1be\WishlistShare\Model;

/**
 * What happened to one recipient.
 *
 * Two values, and only two, because they are the only two a client can act on differently: an
 * address that is not an address is the shopper's typo and can be corrected in the form; a delivery
 * failure is the store's problem and the only sensible advice is "try again later". The reason a
 * mail server gave — "550 5.1.1 User unknown", "421 Too many connections" — is operational detail
 * about the merchant's infrastructure and about the recipient's mailbox, and neither belongs in a
 * response to whoever typed the address.
 */
enum ShareOutcome: string
{
    /** The string is not a syntactically valid email address. Detected before anything is sent. */
    case INVALID_ADDRESS = 'INVALID_ADDRESS';

    /** The address looked fine and the transport refused it or threw. */
    case DELIVERY_FAILED = 'DELIVERY_FAILED';
}
