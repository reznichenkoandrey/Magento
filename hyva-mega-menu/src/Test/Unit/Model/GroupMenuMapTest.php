<?php
declare(strict_types=1);

namespace Scr1be\HyvaMegaMenu\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMegaMenu\Model\GroupMenuMap;

class GroupMenuMapTest extends TestCase
{
    private GroupMenuMap $map;

    protected function setUp(): void
    {
        $this->map = new GroupMenuMap();
    }

    public function testParsesNewlineSeparatedPairs(): void
    {
        $this->assertSame([1 => 5, 2 => 8], $this->map->parse("1:5\n2:8"));
    }

    /**
     * The field is free text in an admin textarea. Whichever separator the merchant reached for,
     * the answer has to be the same one.
     *
     * @dataProvider separatorProvider
     */
    public function testAcceptsEverySeparatorAMerchantMightType(string $raw): void
    {
        $this->assertSame([1 => 5, 2 => 8], $this->map->parse($raw));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function separatorProvider(): array
    {
        return [
            'commas' => ['1:5,2:8'],
            'semicolons' => ['1:5;2:8'],
            'windows newlines' => ["1:5\r\n2:8"],
            'mixed, with spaces' => [" 1 : 5 ,\n 2 : 8 \n"],
            'trailing separator' => ['1:5,2:8,'],
        ];
    }

    /**
     * Group 0 is NOT LOGGED IN — a real group, and the one every guest is in. A parser that cast
     * instead of validating would invent it out of any typo and silently re-point the menu for
     * every anonymous visitor.
     *
     * @dataProvider unusableEntryProvider
     */
    public function testUnusableEntriesAreDroppedRatherThanGuessed(string $raw): void
    {
        $this->assertSame([], $this->map->parse($raw));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableEntryProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ["  \n\t "],
            'no delimiter' => ['15'],
            'two delimiters' => ['1:5:9'],
            'non numeric group' => ['wholesale:5'],
            'non numeric root' => ['1:women'],
            'negative group' => ['-1:5'],
            'decimal root' => ['1:5.0'],
            'root category zero' => ['1:0'],
        ];
    }

    public function testGuestGroupIsAValidKey(): void
    {
        $this->assertSame([0 => 4], $this->map->parse('0:4'));
    }

    public function testAGoodEntrySurvivesABadNeighbour(): void
    {
        $this->assertSame([2 => 8], $this->map->parse("nonsense\n2:8\n:\n"));
    }

    public function testTheLastEntryForAGroupWins(): void
    {
        $this->assertSame([2 => 9], $this->map->parse('2:8,2:9'));
    }

    public function testIsEmptyAgreesWithParse(): void
    {
        $this->assertTrue($this->map->isEmpty('not a mapping'));
        $this->assertFalse($this->map->isEmpty('2:8'));
    }
}
