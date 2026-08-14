<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Api\CurationSourceInterface;
use Scr1be\CuratedCategories\Model\SourcePool;

class SourcePoolTest extends TestCase
{
    public function testRejectsAWronglyTypedEntryAtConstructionTime(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/must implement/');

        new SourcePool(['bestsellers' => new \stdClass()]);
    }

    public function testSeparatesEnabledSourcesFromRegisteredOnes(): void
    {
        $pool = new SourcePool([
            'bestsellers' => $this->source(true),
            'new_arrivals' => $this->source(false),
        ]);

        $this->assertSame(['bestsellers', 'new_arrivals'], $pool->getCodes());
        $this->assertCount(2, $pool->getAll());
        $this->assertSame(['bestsellers'], array_keys($pool->getEnabled()));
    }

    public function testKnowsWhetherACodeIsRegistered(): void
    {
        $pool = new SourcePool(['bestsellers' => $this->source(true)]);

        $this->assertTrue($pool->has('bestsellers'));
        $this->assertFalse($pool->has('bestseller'));
    }

    /**
     * The likely caller is someone typing at a shell, so the message lists what they could have
     * meant.
     */
    public function testNamingAnUnknownSourceListsTheKnownOnes(): void
    {
        $pool = new SourcePool(['bestsellers' => $this->source(true)]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/bestsellers/');

        $pool->get('bestseller');
    }

    private function source(bool $enabled): CurationSourceInterface
    {
        $source = $this->createMock(CurationSourceInterface::class);
        $source->method('isEnabled')->willReturn($enabled);

        return $source;
    }
}
