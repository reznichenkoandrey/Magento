<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Test\Unit\Model\Bundle;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Scr1be\ContentTransfer\Model\Bundle;
use Scr1be\ContentTransfer\Model\Bundle\JsonCodec;
use Scr1be\ContentTransfer\Model\Bundle\Manifest;
use Scr1be\ContentTransfer\Model\Entry;

class JsonCodecTest extends TestCase
{
    public function testTheSameBundleAlwaysEncodesToTheSameBytes(): void
    {
        // The whole reason for committing a bundle: a re-capture of unchanged content produces an
        // empty `git diff`. Anything time-dependent in the format breaks that.
        $codec = new JsonCodec();

        $this->assertSame($codec->encode($this->bundle()), $codec->encode($this->bundle()));
    }

    public function testTheEncodedFormIsOneKeyPerLine(): void
    {
        $json = (new JsonCodec())->encode($this->bundle());

        $this->assertStringContainsString("\n", $json);
        $this->assertStringContainsString('    "manifest"', $json);
    }

    public function testSlashesAreNotEscaped(): void
    {
        // A layout handle or a template path full of `\/` is unreviewable.
        $encoded = (new JsonCodec())->encode(
            new Bundle(
                Manifest::forCapture([], ['cms_page' => 1]),
                [new Entry('cms_page', 'home', ['template' => 'widget/static_block/default.phtml'])]
            )
        );

        $this->assertStringContainsString('widget/static_block/default.phtml', $encoded);
    }

    public function testNonAsciiTextStaysReadable(): void
    {
        $encoded = (new JsonCodec())->encode(
            new Bundle(Manifest::forCapture([], ['cms_page' => 1]), [
                new Entry('cms_page', 'ueber-uns', ['title' => 'Über uns']),
            ])
        );

        $this->assertStringContainsString('Über uns', $encoded);
    }

    public function testAnEncodedBundleDecodesBackToTheSameEntries(): void
    {
        $codec = new JsonCodec();
        $decoded = $codec->decode($codec->encode($this->bundle()));

        $this->assertCount(2, $decoded->getEntries());
        $this->assertSame('cms_block', $decoded->getEntries()[0]->getPorterCode());
        $this->assertSame('footer-links', $decoded->getEntries()[0]->getIdentifier());
        $this->assertSame(['title' => 'Footer links'], $decoded->getEntries()[0]->getPayload());
    }

    public function testCaptureDiagnosticsAreNotPartOfTheFile(): void
    {
        // Transforms and warnings are things that happened during a capture, not properties of the
        // content. Storing them would make the file change when the source install changed.
        $codec = new JsonCodec();
        $encoded = $codec->encode(
            new Bundle(Manifest::forCapture([], ['cms_page' => 1]), [
                new Entry('cms_page', 'home', [], ['rewrote something'], ['could not rewrite something']),
            ])
        );

        $this->assertStringNotContainsString('rewrote something', $encoded);
        $this->assertSame([], $codec->decode($encoded)->getEntries()[0]->getWarnings());
    }

    public function testAnEmptyPayloadEncodesAsAnObjectAndNotAsAList(): void
    {
        $encoded = (new JsonCodec())->encode(
            new Bundle(Manifest::forCapture([], []), [new Entry('cms_page', 'home', [])])
        );

        $this->assertStringContainsString('"payload": {}', $encoded);
    }

    public function testTextThatIsNotJsonIsRejectedWithAReadableMessage(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        (new JsonCodec())->decode('<html>404</html>');
    }

    public function testABundleWithoutAManifestIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/manifest/');

        (new JsonCodec())->decode('{"entries": []}');
    }

    public function testAnEntryWithoutAPorterIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/porter/');

        (new JsonCodec())->decode('{"manifest": {"format": 1}, "entries": [{"identifier": "home"}]}');
    }

    public function testABundleFromANewerFormatIsRefusedRatherThanPartlyRead(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/format/');

        (new JsonCodec())->decode(
            '{"manifest": {"format": ' . (Manifest::FORMAT_VERSION + 1) . '}, "entries": []}'
        );
    }

    private function bundle(): Bundle
    {
        return new Bundle(
            Manifest::forCapture(['de', 'default'], ['cms_page' => 1, 'cms_block' => 1]),
            [
                new Entry('cms_block', 'footer-links', ['title' => 'Footer links']),
                new Entry('cms_page', 'home', ['title' => 'Home']),
            ]
        );
    }
}
