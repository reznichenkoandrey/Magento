<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\ContentTransfer\Model\Selection;

class SelectionTest extends TestCase
{
    public function testAnEmptySelectionMeansEverything(): void
    {
        // `bin/magento content:capture bundle.json` with no options is the first thing anyone
        // types, and it has to mean "all of it".
        $selection = new Selection();

        $this->assertFalse($selection->hasStoreFilter());
        $this->assertTrue($selection->includesPorter('cms_page'));
        $this->assertTrue($selection->includesIdentifier('cms_page', 'anything'));
    }

    public function testNamingOnePorterExcludesTheOthers(): void
    {
        $selection = new Selection([], ['cms_page' => []]);

        $this->assertTrue($selection->includesPorter('cms_page'));
        $this->assertFalse($selection->includesPorter('cms_block'));
    }

    public function testNamingAPorterWithoutIdentifiersTakesAllOfIts(): void
    {
        $this->assertTrue((new Selection([], ['cms_page' => []]))->includesIdentifier('cms_page', 'home'));
    }

    public function testNamingIdentifiersNarrowsToThem(): void
    {
        $selection = new Selection([], ['cms_page' => ['home', 'about-us']]);

        $this->assertTrue($selection->includesIdentifier('cms_page', 'home'));
        $this->assertFalse($selection->includesIdentifier('cms_page', 'contact'));
    }

    public function testAStoreFilterIsReportedAsSuch(): void
    {
        $selection = new Selection([2]);

        $this->assertTrue($selection->hasStoreFilter());
        $this->assertSame([2], $selection->getStoreIds());
    }
}
