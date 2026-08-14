<?php
declare(strict_types=1);

namespace Scr1be\StoreSwitcher\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\StoreSwitcher\Model\StoreOption;

class StoreOptionTest extends TestCase
{
    /**
     * @dataProvider flagCodeProvider
     */
    public function testDerivesTheFlagCodeFromTheLastLocaleSubtag(string $locale, string $expected): void
    {
        self::assertSame($expected, $this->option($locale)->getFlagCode());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function flagCodeProvider(): array
    {
        return [
            'region' => ['en_US', 'us'],
            'script and region' => ['zh_Hans_CN', 'cn'],
            // No region at all: the language subtag is used, which is right for `de` and wrong for
            // `en`. The wrong case resolves to the globe in FlagSprite rather than to a flag.
            'language only' => ['de', 'de'],
            'empty' => ['', ''],
        ];
    }

    public function testRedirectUrlIsAbsentOnTheDrawerVariant(): void
    {
        self::assertNull($this->option('en_US')->getRedirectUrl());
    }

    public function testRedirectUrlIsCarriedWhenTheServerBuiltOne(): void
    {
        $option = new StoreOption(1, 'default', 'English', 'en_US', 'https://example.com/', 'https://example.com/r');

        self::assertSame('https://example.com/r', $option->getRedirectUrl());
    }

    private function option(string $locale): StoreOption
    {
        return new StoreOption(1, 'default', 'English', $locale, 'https://example.com/');
    }
}
