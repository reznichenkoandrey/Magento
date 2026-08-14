<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Test\Unit\Model\Search;

use PHPUnit\Framework\TestCase;
use Scr1be\PosBridge\Model\Search\QueryTokenizer;
use Scr1be\PosBridge\Model\Search\Token;

class QueryTokenizerTest extends TestCase
{
    private QueryTokenizer $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new QueryTokenizer();
    }

    public function testEachWordBecomesOneToken(): void
    {
        $this->assertSame(['jane', 'smith'], $this->terms('jane smith'));
    }

    public function testRunsOfWhitespaceAreOneSeparator(): void
    {
        $this->assertSame(['jane', 'smith'], $this->terms("  jane \t\n  smith  "));
    }

    /**
     * Anything that pastes a formatted phone number can bring a non-breaking space with it. The
     * assertion is on the tokenizer's own rule rather than on PCRE's: `\s` under `/u` happens to
     * match U+00A0 already, so this pins the behaviour to the module rather than to the engine.
     */
    public function testNonBreakingSpacesSeparateTermsToo(): void
    {
        $this->assertSame(['555', '2293326'], $this->terms("555\u{00A0}2293326"));
    }

    public function testAQueryOfSeparatorsProducesNothing(): void
    {
        $this->assertSame([], $this->tokenizer->tokenize("  \t \u{00A0} "));
    }

    /**
     * Dropping surplus terms only removes restrictions, so the operator gets a wider list and picks
     * from it. Rejecting the query would be a dead end in front of someone at a till.
     */
    public function testSurplusTermsAreDroppedRatherThanRejected(): void
    {
        $query = implode(' ', array_map(
            static fn (int $index): string => 'term' . $index,
            range(1, QueryTokenizer::MAX_TOKENS + 5)
        ));

        $terms = $this->terms($query);

        $this->assertCount(QueryTokenizer::MAX_TOKENS, $terms);
        $this->assertSame('term1', $terms[0]);
        $this->assertSame('term' . QueryTokenizer::MAX_TOKENS, $terms[QueryTokenizer::MAX_TOKENS - 1]);
    }

    public function testTokensCarryTheirDigitForm(): void
    {
        $tokens = $this->tokenizer->tokenize('smith (555) 229-3326');

        $this->assertFalse($tokens[0]->isPhoneCandidate());
        $this->assertTrue($tokens[1]->isPhoneCandidate());
        $this->assertSame('2293326', $tokens[2]->getDigits());
    }

    /**
     * @dataProvider lengthProvider
     */
    public function testTheLengthRuleCountsTypedCharactersNotWhitespace(string $query, bool $expected): void
    {
        $this->assertSame($expected, $this->tokenizer->isLongEnough($query));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function lengthProvider(): array
    {
        return [
            'exactly the minimum' => ['abc', true],
            'one short' => ['ab', false],
            'empty' => ['', false],
            'whitespace only' => ["   \t ", false],
            // Three characters of which two are spaces is two one-letter terms; counting the raw
            // string would accept it and defeat the rule.
            'padding does not buy length' => ['a b', false],
            'spread across terms, still long enough' => ['ab cd', true],
            'multibyte characters count as one each' => ['Ünal', true],
            'two multibyte characters are still two' => ['Üé', false],
        ];
    }

    /**
     * @return string[]
     */
    private function terms(string $query): array
    {
        return array_map(
            static fn (Token $token): string => $token->getTerm(),
            $this->tokenizer->tokenize($query)
        );
    }
}
