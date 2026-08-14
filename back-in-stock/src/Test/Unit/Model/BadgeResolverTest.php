<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\BackInStock\Model\BadgeResolver;

class BadgeResolverTest extends TestCase
{
    private TimezoneInterface&MockObject $timezone;
    private BadgeResolver $resolver;

    protected function setUp(): void
    {
        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->timezone->method('scopeDate')->willReturn(new \DateTime('2026-06-15 12:00:00'));
        $this->resolver = new BadgeResolver($this->timezone);
    }

    public function testLowStockFiresAtTheThresholdAndNotAbove(): void
    {
        $this->assertSame(
            [BadgeResolver::BADGE_LOW_STOCK],
            $this->codes($this->resolver->resolve($this->product(), 5.0, 10.0, 10.0, 5))
        );

        $this->assertSame([], $this->codes($this->resolver->resolve($this->product(), 6.0, 10.0, 10.0, 5)));
    }

    public function testAThresholdOfZeroSwitchesTheClaimOffEntirely(): void
    {
        $this->assertSame([], $this->codes($this->resolver->resolve($this->product(), 1.0, 10.0, 10.0, 0)));
    }

    public function testNothingIsClaimedAboutAProductWithNoStockRow(): void
    {
        // A quantity of zero means the stock item was missing, not that the last unit just sold.
        $this->assertSame([], $this->codes($this->resolver->resolve($this->product(), 0.0, 10.0, 10.0, 5)));
    }

    public function testTheDiscountBadgeCarriesTheRoundedPercentage(): void
    {
        $badges = $this->resolver->resolve($this->product(), 0.0, 80.0, 100.0, 0);

        $this->assertSame([BadgeResolver::BADGE_DISCOUNT], $this->codes($badges));
        $this->assertSame('-20%', $badges[0]['label']);
    }

    public function testARoundingArtefactDoesNotProduceAMinusZeroBadge(): void
    {
        // Fifty cents off five hundred euros is not a discount worth a badge, and "-0%" reads as a
        // bug rather than as a saving.
        $this->assertSame([], $this->codes($this->resolver->resolve($this->product(), 0.0, 499.5, 500.0, 0)));
    }

    public function testAProductWithNoRegularPriceIsNeverDiscounted(): void
    {
        $this->assertSame([], $this->codes($this->resolver->resolve($this->product(), 0.0, 10.0, 0.0, 0)));
    }

    public function testTheNewWindowIsInclusiveOnBothEnds(): void
    {
        $inside = $this->product(['news_from_date' => '2026-06-15 00:00:00', 'news_to_date' => '2026-06-15']);
        $this->assertSame([BadgeResolver::BADGE_NEW], $this->codes($this->resolver->resolve($inside, 0.0, 10.0, 10.0, 0)));
    }

    public function testAnOpenEndedNewWindowStaysOpen(): void
    {
        $product = $this->product(['news_from_date' => '2026-01-01']);
        $this->assertSame([BadgeResolver::BADGE_NEW], $this->codes($this->resolver->resolve($product, 0.0, 10.0, 10.0, 0)));
    }

    public function testAnExpiredNewWindowIsNotNew(): void
    {
        $product = $this->product(['news_from_date' => '2026-01-01', 'news_to_date' => '2026-02-01']);
        $this->assertSame([], $this->codes($this->resolver->resolve($product, 0.0, 10.0, 10.0, 0)));
    }

    public function testBlankDateAttributesAreNotADate(): void
    {
        // EAV hands back an empty string for an attribute that was set and then cleared, and the
        // string comparison would treat "" as "before today".
        $product = $this->product(['news_from_date' => '   ', 'news_to_date' => '']);
        $this->assertSame([], $this->codes($this->resolver->resolve($product, 0.0, 10.0, 10.0, 0)));
    }

    public function testUrgencyComesBeforeEverythingElse(): void
    {
        $product = $this->product(['news_from_date' => '2026-01-01']);

        $this->assertSame(
            [BadgeResolver::BADGE_LOW_STOCK, BadgeResolver::BADGE_DISCOUNT, BadgeResolver::BADGE_NEW],
            $this->codes($this->resolver->resolve($product, 2.0, 80.0, 100.0, 5))
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function product(array $data = []): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturnCallback(
            static fn (string $key) => $data[$key] ?? null
        );

        return $product;
    }

    /**
     * @param array<int, array{code: string, label: string}> $badges
     * @return string[]
     */
    private function codes(array $badges): array
    {
        return array_column($badges, 'code');
    }
}
