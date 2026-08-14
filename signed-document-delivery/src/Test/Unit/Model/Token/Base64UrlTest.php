<?php
declare(strict_types=1);

namespace Scr1be\SignedDocumentDelivery\Test\Unit\Model\Token;

use PHPUnit\Framework\TestCase;
use Scr1be\SignedDocumentDelivery\Model\Token\Base64Url;

class Base64UrlTest extends TestCase
{
    /**
     * @dataProvider roundTrips
     */
    public function testEveryByteSurvivesARoundTrip(string $raw): void
    {
        $this->assertSame($raw, Base64Url::decode(Base64Url::encode($raw)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function roundTrips(): array
    {
        return [
            'one byte' => ['a'],
            'two bytes, one pad character' => ['ab'],
            'three bytes, no padding' => ['abc'],
            'json payload' => ['{"v":1,"t":"INVOICE","d":"NA==","c":42}'],
            // 32 raw bytes is the length of an HMAC-SHA256, the thing this actually encodes.
            'raw hmac bytes' => [hash_hmac('sha256', 'message', 'key', true)],
            'every byte value' => [implode('', array_map('chr', range(0, 255)))],
        ];
    }

    public function testTheOutputIsUrlSafe(): void
    {
        // Bytes chosen so that plain base64 produces both a "+" and a "/".
        $encoded = Base64Url::encode("\xfb\xff\xbf");

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $encoded);
        $this->assertStringNotContainsString('=', $encoded, 'padding must be stripped');
    }

    public function testAnUnpaddedThirtyTwoByteMacIsFortyThreeCharacters(): void
    {
        // The verifier compares MACs in this form, so their length has to be fixed.
        $this->assertSame(43, strlen(Base64Url::encode(random_bytes(32))));
    }

    /**
     * @dataProvider rejectedInputs
     */
    public function testRejectsAnythingThatIsNotBase64Url(string $input): void
    {
        $this->assertNull(Base64Url::decode($input));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedInputs(): array
    {
        return [
            'empty' => [''],
            'plain base64 plus' => ['ab+cd'],
            'plain base64 slash' => ['ab/cd'],
            'padding character' => ['abcd='],
            'a dot, which is the token separator' => ['ab.cd'],
            'whitespace' => ["abcd\n"],
            'unicode' => ['abcdé'],
        ];
    }
}
