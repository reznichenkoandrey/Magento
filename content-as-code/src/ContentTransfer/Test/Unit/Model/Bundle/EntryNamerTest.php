<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Test\Unit\Model\Bundle;

use PHPUnit\Framework\TestCase;
use Scr1be\ContentTransfer\Model\Bundle\EntryNamer;
use Scr1be\ContentTransfer\Model\Entry;

class EntryNamerTest extends TestCase
{
    public function testACleanIdentifierIsUsedVerbatim(): void
    {
        $this->assertSame('about-us', (new EntryNamer())->slug('about-us'));
        $this->assertSame('footer_links.v2', (new EntryNamer())->slug('footer_links.v2'));
    }

    public function testTheArchivePathIsThePorterDirectoryAndTheSlug(): void
    {
        $this->assertSame(
            'cms_page/about-us.json',
            (new EntryNamer())->path(new Entry('cms_page', 'about-us', []))
        );
    }

    public function testALossySlugCarriesADigestOfWhatItLost(): void
    {
        $slug = (new EntryNamer())->slug('Über uns');

        $this->assertStringStartsWith('ber-uns-', $slug);
        $this->assertMatchesRegularExpression('/^ber-uns-[0-9a-f]{8}$/', $slug);
    }

    public function testTwoIdentifiersThatSlugifyTheSameStayDistinct(): void
    {
        // `about us` and `about-us` are different CMS entities and must be different files. An
        // archive that quietly keeps one of them is an export that lost content.
        $namer = new EntryNamer();

        $this->assertNotSame($namer->slug('about us'), $namer->slug('about/us'));
    }

    public function testTheSameIdentifierAlwaysProducesTheSameName(): void
    {
        $namer = new EntryNamer();

        $this->assertSame($namer->slug('Home @ default'), $namer->slug('Home @ default'));
    }

    public function testAnIdentifierWithNothingSluggableStillGetsAName(): void
    {
        $slug = (new EntryNamer())->slug('***');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $slug);
    }

    public function testAVeryLongIdentifierIsTruncatedButStillUnique(): void
    {
        $namer = new EntryNamer();
        $first = $namer->slug(str_repeat('a', 200) . 'one');
        $second = $namer->slug(str_repeat('a', 200) . 'two');

        $this->assertLessThanOrEqual(80, strlen($first));
        $this->assertNotSame($first, $second);
    }

    public function testACleanIdentifierAtTheLengthLimitIsNotDigested(): void
    {
        $identifier = str_repeat('a', 80);

        $this->assertSame($identifier, (new EntryNamer())->slug($identifier));
    }
}
