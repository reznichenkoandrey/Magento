<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Model\Source;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Model\Config;
use Scr1be\CuratedCategories\Model\Exclusion\ProductFilter;
use Scr1be\CuratedCategories\Model\Exclusion\RuleReader;
use Scr1be\CuratedCategories\Model\Exclusion\RuleSet;
use Scr1be\CuratedCategories\Model\ResourceModel\ArrivalIndex;
use Scr1be\CuratedCategories\Model\Source\NewArrivals;

class NewArrivalsTest extends TestCase
{
    private const LIMIT = 4;
    private const WINDOW_DAYS = 30;

    private Config&MockObject $config;
    private ArrivalIndex&MockObject $arrivalIndex;
    private ProductFilter&MockObject $productFilter;
    private NewArrivals $source;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getLimit')->willReturn(self::LIMIT);
        $this->config->method('getWindowDays')->willReturn(self::WINDOW_DAYS);

        $this->arrivalIndex = $this->createMock(ArrivalIndex::class);
        $this->productFilter = $this->createMock(ProductFilter::class);

        $ruleReader = $this->createMock(RuleReader::class);
        $ruleReader->method('read')->willReturn(new RuleSet([], RuleSet::MATCH_ANY));

        $this->source = new NewArrivals(
            $this->config,
            $this->timezone(),
            $this->arrivalIndex,
            $ruleReader,
            $this->productFilter
        );
    }

    /**
     * Exclusions are applied to what the query already picked, so asking for exactly the limit would
     * let a single rule shorten the feed. The query asks for three times as many and the slice
     * happens afterwards.
     */
    public function testOverFetchesBeforeExcludingAndTrimsAfterwards(): void
    {
        $this->arrivalIndex->expects($this->once())
            ->method('getRecentArrivals')
            ->with($this->anything(), self::LIMIT * 3)
            ->willReturn([1, 2, 3, 4, 5, 6, 7, 8]);

        $this->productFilter->method('apply')->willReturn([2, 4, 6, 8, 10]);

        $this->assertSame([2, 4, 6, 8], $this->source->getProductIds());
    }

    public function testWindowBoundaryIsExpressedInUtc(): void
    {
        $this->arrivalIndex->expects($this->once())
            ->method('getRecentArrivals')
            ->with('2026-07-15 09:00:00', $this->anything())
            ->willReturn([]);

        $this->productFilter->method('apply')->willReturn([]);

        $this->source->getProductIds();
    }

    public function testAProductWithNoArrivalRowDoesNotQualify(): void
    {
        $this->arrivalIndex->method('getArrivalDate')->willReturn(null);
        $this->productFilter->expects($this->never())->method('apply');

        $this->assertFalse($this->source->qualifies(9));
    }

    public function testAProductThatArrivedBeforeTheWindowDoesNotQualify(): void
    {
        $this->arrivalIndex->method('getArrivalDate')->willReturn('2026-01-01 00:00:00');
        $this->productFilter->expects($this->never())->method('apply');

        $this->assertFalse($this->source->qualifies(9));
    }

    /**
     * The observer and the hourly reconcile have to agree, or every incremental add is undone an
     * hour later — so `qualifies()` runs the same exclusion rules the feed does.
     */
    public function testAnExcludedProductDoesNotQualifyEvenInsideTheWindow(): void
    {
        $this->arrivalIndex->method('getArrivalDate')->willReturn('2026-08-01 00:00:00');
        $this->productFilter->method('apply')->willReturn([]);

        $this->assertFalse($this->source->qualifies(9));
    }

    public function testAFreshUnexcludedProductQualifies(): void
    {
        $this->arrivalIndex->method('getArrivalDate')->willReturn('2026-08-01 00:00:00');
        $this->productFilter->method('apply')->willReturn([9]);

        $this->assertTrue($this->source->qualifies(9));
    }

    public function testHasNoTargetUntilACategoryIsPicked(): void
    {
        $this->config->method('getCategoryId')->willReturn(0);

        $this->assertNull($this->source->getTarget());
    }

    public function testTargetCarriesTheConfiguredCategoryAndFloor(): void
    {
        $this->config->method('getCategoryId')->willReturn(17);
        $this->config->method('getMinimumFloor')->willReturn(6);

        $target = $this->source->getTarget();

        $this->assertNotNull($target);
        $this->assertSame(17, $target->getCategoryId());
        $this->assertSame(6, $target->getMinimumFloor());
        $this->assertSame(NewArrivals::CODE, $target->getSourceCode());
    }

    /**
     * A fixed "now" in a non-UTC zone, so a boundary that skipped the conversion would be visibly
     * three hours out rather than accidentally right.
     */
    private function timezone(): TimezoneInterface&MockObject
    {
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturnCallback(
            static fn (): \DateTime => new \DateTime('2026-08-14 12:00:00', new \DateTimeZone('Europe/Kyiv'))
        );

        return $timezone;
    }
}
