<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Test\Unit\Model\Search;

use PHPUnit\Framework\TestCase;
use Scr1be\PosBridge\Model\Search\Token;

class TokenTest extends TestCase
{
    public function testTheTermIsKeptExactlyAsTyped(): void
    {
        $token = new Token("O'Brien");

        $this->assertSame("O'Brien", $token->getTerm());
    }

    /**
     * @dataProvider digitProvider
     */
    public function testEveryNonDigitIsStripped(string $term, string $expected): void
    {
        $this->assertSame($expected, (new Token($term))->getDigits());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function digitProvider(): array
    {
        return [
            'plain digits' => ['5552293326', '5552293326'],
            'dashes' => ['555-229-3326', '5552293326'],
            'parentheses' => ['(555)', '555'],
            'an international prefix' => ['+15552293326', '15552293326'],
            'a house number inside a word' => ['flat12b', '12'],
            'no digits at all' => ['Smith', ''],
            'punctuation only' => ['---', ''],
        ];
    }

    /**
     * @dataProvider phoneCandidateProvider
     */
    public function testTheDigitThresholdDecidesWhetherThePhoneBranchIsUsed(string $term, bool $expected): void
    {
        $this->assertSame($expected, (new Token($term))->isPhoneCandidate());
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function phoneCandidateProvider(): array
    {
        return [
            'a word is not a phone fragment' => ['Smith', false],
            'one digit is a house number' => ['7', false],
            'two digits are still not enough' => ['42', false],
            'three digits reach the threshold' => ['326', true],
            'three digits behind punctuation still count' => ['(326)', true],
            'a full number' => ['555-229-3326', true],
            // The digits are counted, not the characters: a long term with two digits in it is not
            // a phone fragment however long the term is.
            'a long term with two digits is not a fragment' => ['Apartment-4B-2', false],
        ];
    }
}
