<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Model\Search;

/**
 * One whitespace-separated term, plus the only derived fact the query needs about it: what it looks
 * like with every non-digit removed.
 *
 * The derivation lives here rather than in the query builder because it is the half of the phone
 * rule that can be reasoned about without a database. `"(555)"` and `"555"` are the same phone
 * fragment; whether `555` is *long enough* to be treated as one is the other half, and it is a
 * policy question, so it is a named constant rather than a literal buried in a condition.
 */
class Token
{
    /**
     * Below this, a digit run is an apartment number, a house number or a street number far more
     * often than it is a piece of a phone number, and matching it against every billing telephone
     * in the installation returns noise the operator has to read past.
     */
    public const MIN_PHONE_DIGITS = 3;

    private readonly string $digits;

    public function __construct(
        private readonly string $term
    ) {
        $this->digits = preg_replace('/\D+/', '', $term) ?? '';
    }

    /**
     * The term exactly as typed. Matched against the text columns, so punctuation the operator typed
     * is matched too — `O'Brien` must find `O'Brien`.
     */
    public function getTerm(): string
    {
        return $this->term;
    }

    /**
     * The term with every non-digit removed, matched against the equally stripped billing telephone.
     */
    public function getDigits(): string
    {
        return $this->digits;
    }

    public function isPhoneCandidate(): bool
    {
        return strlen($this->digits) >= self::MIN_PHONE_DIGITS;
    }
}
