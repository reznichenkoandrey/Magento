<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Test\Unit\Model\Hreflang;

use PHPUnit\Framework\TestCase;
use Scr1be\StoreSeo\Model\Hreflang\LocaleFormatter;

class LocaleFormatterTest extends TestCase
{
    private LocaleFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new LocaleFormatter();
    }

    /**
     * @dataProvider validLocaleProvider
     */
    public function testConvertsIcuLocalesToBcp47(string $magentoLocale, string $expected): void
    {
        self::assertSame($expected, $this->formatter->format($magentoLocale));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validLocaleProvider(): array
    {
        return [
            'language and region' => ['en_US', 'en-US'],
            'brazilian portuguese' => ['pt_BR', 'pt-BR'],
            'script subtag' => ['zh_Hans_CN', 'zh-Hans-CN'],
            'language only' => ['de', 'de'],
            'three letter language' => ['fil_PH', 'fil-PH'],
        ];
    }

    /**
     * @dataProvider invalidLocaleProvider
     */
    public function testRejectsAnythingThatWouldNotBeALegalTag(?string $magentoLocale): void
    {
        self::assertNull($this->formatter->format($magentoLocale));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function invalidLocaleProvider(): array
    {
        return [
            'unset' => [null],
            'blank' => ['   '],
            'single letter language' => ['e_US'],
            'punctuation' => ['en_US;'],
            'trailing separator' => ['en_'],
        ];
    }
}
