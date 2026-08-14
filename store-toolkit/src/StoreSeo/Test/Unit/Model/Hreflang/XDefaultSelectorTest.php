<?php
declare(strict_types=1);

namespace Scr1be\StoreSeo\Test\Unit\Model\Hreflang;

use PHPUnit\Framework\TestCase;
use Scr1be\StoreSeo\Model\Hreflang\AlternateLink;
use Scr1be\StoreSeo\Model\Hreflang\XDefaultSelector;

class XDefaultSelectorTest extends TestCase
{
    private XDefaultSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new XDefaultSelector();
    }

    public function testPrefersTheNominatedPrimaryStore(): void
    {
        $links = [
            new AlternateLink(2, 'de-DE', 'https://example.com/de/p.html'),
            new AlternateLink(1, 'en-US', 'https://example.com/p.html'),
        ];

        $selected = $this->selector->select($links, 1, 'en');

        self::assertNotNull($selected);
        self::assertSame('en-US', $selected->getHreflang());
    }

    public function testFallsBackToTheSameLanguageWhenThePrimaryIsMissingFromThisPage(): void
    {
        // The US store does not carry this product, so store 1 is absent from the group. The UK
        // page is a far better default for undirected traffic than the German one.
        $links = [
            new AlternateLink(2, 'de-DE', 'https://example.com/de/p.html'),
            new AlternateLink(3, 'en-GB', 'https://example.co.uk/p.html'),
        ];

        $selected = $this->selector->select($links, 1, 'en');

        self::assertNotNull($selected);
        self::assertSame('en-GB', $selected->getHreflang());
    }

    public function testFallsBackToTheFirstAlternateWhenNothingElseMatches(): void
    {
        $links = [
            new AlternateLink(2, 'de-DE', 'https://example.com/de/p.html'),
            new AlternateLink(4, 'fr-FR', 'https://example.fr/p.html'),
        ];

        $selected = $this->selector->select($links, 1, 'en');

        self::assertNotNull($selected);
        self::assertSame('de-DE', $selected->getHreflang());
    }

    public function testUnconfiguredPrimaryStillProducesAnXDefault(): void
    {
        $links = [new AlternateLink(2, 'de-DE', 'https://example.com/de/p.html')];

        $selected = $this->selector->select($links, null, null);

        self::assertNotNull($selected);
        self::assertSame('de-DE', $selected->getHreflang());
    }

    public function testNoAlternatesMeansNoXDefault(): void
    {
        self::assertNull($this->selector->select([], 1, 'en'));
    }
}
