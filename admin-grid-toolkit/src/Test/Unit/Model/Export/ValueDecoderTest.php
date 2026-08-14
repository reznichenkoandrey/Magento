<?php
declare(strict_types=1);

namespace Scr1be\AdminGridToolkit\Test\Unit\Model\Export;

use PHPUnit\Framework\TestCase;
use Scr1be\AdminGridToolkit\Model\Export\ValueDecoder;

class ValueDecoderTest extends TestCase
{
    private ValueDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new ValueDecoder();
    }

    /**
     * @dataProvider entityProvider
     */
    public function testEntitiesBecomeTheCharactersTheyStandFor(string $rendered, string $expected): void
    {
        $this->assertSame($expected, $this->decoder->decode($rendered));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function entityProvider(): array
    {
        return [
            'ampersand in a category name' => ['Hoodies &amp; Sweatshirts', 'Hoodies & Sweatshirts'],
            'apostrophe in a customer name' => ['O&#039;Brien', "O'Brien"],
            'double quote in a product name' => ['21&quot; Monitor', '21" Monitor'],
            'named entity outside ASCII' => ['Caf&eacute; Table', 'Café Table'],
            'angle brackets the shopper typed' => ['&lt;b&gt;bold&lt;/b&gt;', '<b>bold</b>'],
        ];
    }

    /**
     * The renderers' own markup is the noise; the text inside it is the value.
     *
     * @dataProvider markupProvider
     */
    public function testRendererMarkupIsRemoved(string $rendered, string $expected): void
    {
        $this->assertSame($expected, $this->decoder->decode($rendered));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function markupProvider(): array
    {
        return [
            'editable column control' => [
                '<div class="admin__grid-control">'
                . '<span class="admin__grid-control-value">12</span>'
                . '<input type="text" name="qty" value="12"/></div>',
                '12',
            ],
            'action renderer link' => ['<a href="http://example.test/edit/1/">Edit</a>', 'Edit'],
            'line break joins two values' => [
                'Main Website<br />Main Website Store',
                'Main Website Main Website Store',
            ],
            'bare and self-closing breaks alike' => ['a<br>b<br/>c', 'a b c'],
            'markup leaves no surrounding whitespace behind' => ['<span> value </span>', 'value'],
            'markup and entities together' => [
                '<span>Hoodies &amp; Sweatshirts</span>',
                'Hoodies & Sweatshirts',
            ],
        ];
    }

    /**
     * Everything that has neither a tag nor an entity has to come back byte for byte — including
     * the leading spaces the Store renderer uses to indent a store-view tree, which are data.
     *
     * @dataProvider untouchedProvider
     */
    public function testValuesWithNothingToDecodeAreReturnedUnchanged(string $rendered): void
    {
        $this->assertSame($rendered, $this->decoder->decode($rendered));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function untouchedProvider(): array
    {
        return [
            'plain word' => ['Complete'],
            'formatted money' => ['$1,234.56'],
            'store view tree' => ["Main Website\r\n   Main Website Store\r\n      Default Store View"],
            'padded value' => ['  spaced  '],
            'empty' => [''],
        ];
    }

    /**
     * Decoding happens once. A value that renders as "&amp;lt;" is a value whose text was "&lt;",
     * and it has to stay text rather than turn into a bracket on the way to a spreadsheet.
     */
    public function testDecodingIsASinglePass(): void
    {
        $this->assertSame('&lt;', $this->decoder->decode('&amp;lt;'));
    }

    /**
     * The escape this reverses is not injective: Magento escapes with double_encode disabled, so a
     * value that literally read "&amp;" and a value that read "&" produce the same rendered string.
     * The distinction is gone before this class sees it, and the test states that plainly rather
     * than pretending the round trip is lossless.
     */
    public function testALiteralEntityInTheDataIsIndistinguishableFromTheCharacter(): void
    {
        // Magento\Framework\Escaper::escapeHtml() ends in exactly this call, double_encode off.
        $escape = static fn (string $raw): string => htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);

        $this->assertSame($escape('AT&T'), $escape('AT&amp;T'));
        $this->assertSame('AT&T', $this->decoder->decode($escape('AT&amp;T')));
    }
}
