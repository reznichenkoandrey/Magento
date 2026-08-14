<?php
declare(strict_types=1);

namespace Scr1be\TierPriceLabel\Test\Unit\Model;

use Magento\Framework\Locale\ResolverInterface;
use PHPUnit\Framework\TestCase;
use Scr1be\TierPriceLabel\Model\QtyFormatter;

class QtyFormatterTest extends TestCase
{
    private QtyFormatter $formatter;

    protected function setUp(): void
    {
        $localeResolver = $this->createMock(ResolverInterface::class);
        $localeResolver->method('getLocale')->willReturn('en_US');

        $this->formatter = new QtyFormatter($localeResolver);
    }

    public function testWholeQuantitiesLoseTheirStoredDecimals(): void
    {
        // The column is DECIMAL(12,4); "5.0000" must never reach the label.
        $this->assertSame('5', $this->formatter->format(5.0));
    }

    public function testFractionalQuantitiesKeepOnlyTheSignificantDecimals(): void
    {
        $this->assertSame('1.5', $this->formatter->format(1.5));
    }
}
