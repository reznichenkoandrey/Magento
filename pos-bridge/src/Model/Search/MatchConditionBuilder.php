<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Model\Search;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Helper as DbHelper;

/**
 * Turns one token into one parenthesised SQL condition.
 *
 * This is the module's seam against the database layer: everything above it works in terms of
 * tokens, everything below it works in terms of a `Select`, and the two contracts that can drift —
 * how a LIKE pattern is escaped, and how an identifier is quoted — are both crossed here. That is
 * why it is a class of its own with its own test rather than three lines inside the query builder.
 *
 * Escaping is delegated, not reimplemented. `DbHelper::escapeLikeValue()` is core's own routine for
 * neutralising `%`, `_` and `\` in a value that is about to become a LIKE pattern; a hand-rolled
 * version is the kind of thing that looks right for years and then lets a shopper search for `%`
 * and get the entire customer table.
 */
class MatchConditionBuilder
{
    /**
     * The columns a typed term is matched against, in the aliases the query builder joins under.
     *
     * `middlename` is deliberately absent from both sides. It is not what an operator types when
     * they are looking at someone, and every column here widens every token's OR block — the cost
     * is paid on every search, for a field that is empty on almost every row.
     */
    public const TEXT_COLUMNS = [
        CustomerColumns::CUSTOMER_ALIAS . '.email',
        CustomerColumns::CUSTOMER_ALIAS . '.firstname',
        CustomerColumns::CUSTOMER_ALIAS . '.lastname',
        CustomerColumns::BILLING_ALIAS . '.firstname',
        CustomerColumns::BILLING_ALIAS . '.lastname',
    ];

    /**
     * Characters people put between the digits of a phone number. Stripped from the stored value so
     * that `5552293326` finds `(555) 229-3326`, which is how the number was typed into the address
     * book and is never how it is read out at a counter.
     *
     * A fixed list of literals rather than a regular-expression replace: `REGEXP_REPLACE` is a
     * MySQL 8 / MariaDB 10.0 function and `REPLACE` is not, so the nested form runs everywhere the
     * rest of the module does.
     */
    public const PHONE_SEPARATORS = [' ', '-', '(', ')', '.', '+', '/', "\t"];

    public function __construct(
        private readonly DbHelper $dbHelper
    ) {
    }

    /**
     * One token, as a single `(a LIKE … OR b LIKE … [OR digits(phone) LIKE …])` group.
     *
     * The caller ANDs these together, so a two-word query means both words must be found — each in
     * any of the columns, not necessarily the same one. That is what makes `"jane smith"` match a
     * Jane whose account name is `Jane Doe` and whose card says `Smith`, which is the case a
     * per-column search gets wrong.
     */
    public function forToken(AdapterInterface $connection, Token $token): string
    {
        $alternatives = [];

        $pattern = $this->likePattern($token->getTerm());
        foreach (self::TEXT_COLUMNS as $column) {
            $alternatives[] = $connection->quoteIdentifier($column) . ' LIKE ' . $connection->quote($pattern);
        }

        if ($token->isPhoneCandidate()) {
            $alternatives[] = $this->digitsOnlyTelephone($connection)
                . ' LIKE '
                . $connection->quote($this->likePattern($token->getDigits()));
        }

        return '(' . implode(' OR ', $alternatives) . ')';
    }

    /**
     * The stored billing telephone with every separator removed, so it can be compared with a
     * digits-only term.
     *
     * Wrapping the column in an expression makes this half of the condition unindexable, and that is
     * a real cost rather than an oversight: it is why the phone branch is only emitted for terms of
     * {@see Token::MIN_PHONE_DIGITS} digits or more, and why the whole endpoint is capped and sits
     * behind an admin ACL rather than being exposed to shoppers.
     */
    public function digitsOnlyTelephone(AdapterInterface $connection): string
    {
        $expression = $connection->quoteIdentifier(CustomerColumns::BILLING_ALIAS . '.telephone');

        foreach (self::PHONE_SEPARATORS as $separator) {
            $expression = sprintf(
                'REPLACE(%s, %s, %s)',
                $expression,
                $connection->quote($separator),
                $connection->quote('')
            );
        }

        return $expression;
    }

    /**
     * A contains-match with the wildcard characters in the operator's own text neutralised.
     */
    private function likePattern(string $value): string
    {
        return $this->dbHelper->escapeLikeValue($value, ['position' => 'any']);
    }
}
