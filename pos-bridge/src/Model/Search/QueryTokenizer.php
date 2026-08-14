<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Model\Search;

/**
 * Free text in, terms out. No database, no configuration, no I/O — the whole search policy that can
 * be argued about on a whiteboard lives in this class, which is why it is the one with the densest
 * test file.
 */
class QueryTokenizer
{
    /**
     * Each term becomes one more AND-ed block of ORs in the WHERE clause, so an unbounded term count
     * is an unbounded query. Surplus terms are dropped rather than rejected: dropping a term only
     * removes a restriction, so the operator gets a wider result set and picks from it, whereas a
     * validation error in front of someone holding a card reader is a dead end.
     */
    public const MAX_TOKENS = 8;

    /**
     * A shorter query than this matches a meaningful fraction of the customer table, and the cap on
     * results would make the answer arbitrary rather than wrong-and-obvious. Better to say so.
     */
    public const MIN_QUERY_LENGTH = 3;

    /**
     * @return Token[] Empty when the query holds nothing but separators.
     */
    public function tokenize(string $query): array
    {
        $terms = preg_split('/\s+/u', $this->normalize($query), -1, PREG_SPLIT_NO_EMPTY);
        if ($terms === false || $terms === []) {
            return [];
        }

        return array_map(
            static fn (string $term): Token => new Token($term),
            array_slice($terms, 0, self::MAX_TOKENS)
        );
    }

    /**
     * The length rule counts characters the operator meant to type, not the whitespace between them:
     * `"a b"` is two one-letter terms, and accepting it because the raw string is three characters
     * long would defeat the rule it is there to enforce.
     */
    public function isLongEnough(string $query): bool
    {
        $withoutSeparators = preg_replace('/\s+/u', '', $this->normalize($query)) ?? '';

        return mb_strlen($withoutSeparators) >= self::MIN_QUERY_LENGTH;
    }

    /**
     * Non-breaking spaces arrive from anything that pastes a formatted phone number, and a term with
     * one embedded in it matches nothing.
     *
     * PHP's `u` modifier enables Unicode character properties as well as UTF-8 mode, so `\s` already
     * matches U+00A0 on a stock build and the split below would handle this unaided. The replacement
     * is kept because that is a property of the regex engine rather than of this module's rules: it
     * states the separator set the tokenizer means, and it guarantees {@see tokenize()} and
     * {@see isLongEnough()} agree about it — a query rejected as too short must be short by the same
     * definition of "whitespace" that would have split it.
     */
    private function normalize(string $query): string
    {
        return trim(str_replace("\u{00A0}", ' ', $query));
    }
}
