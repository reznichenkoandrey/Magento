<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Model\Search;

/**
 * The shared vocabulary of the search: the two table aliases the select joins under, and the keys
 * the fetched rows come back with.
 *
 * Three classes have to agree on these strings — the condition builder writes them into SQL, the
 * query builder selects them, and the lookup service reads them off the row. A literal repeated
 * across three files is a rename waiting to break exactly one of them silently, which is the one
 * bug in this module a unit test would not catch, because every test would be renamed with it.
 */
final class CustomerColumns
{
    public const CUSTOMER_ALIAS = 'customer';
    public const BILLING_ALIAS = 'billing';

    public const CUSTOMER_ID = 'customer_id';
    public const FIRSTNAME = 'firstname';
    public const LASTNAME = 'lastname';
    public const EMAIL = 'email';
    public const WEBSITE_ID = 'website_id';
    public const GROUP_ID = 'group_id';
    public const BILLING_FIRSTNAME = 'billing_firstname';
    public const BILLING_LASTNAME = 'billing_lastname';
    public const BILLING_TELEPHONE = 'billing_telephone';
}
