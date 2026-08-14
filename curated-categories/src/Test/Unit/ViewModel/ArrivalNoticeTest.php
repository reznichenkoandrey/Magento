<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Model\Config;
use Scr1be\CuratedCategories\Model\Source\ComingSoon;
use Scr1be\CuratedCategories\ViewModel\ArrivalNotice;

class ArrivalNoticeTest extends TestCase
{
    private const TIMEZONE = 'Europe/Kyiv';
    private const TODAY = '2026-08-14 12:00:00';

    private Config&MockObject $config;
    private ArrivalNotice $viewModel;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->viewModel = new ArrivalNotice($this->config, $this->timezone());
    }

    public function testSaysNothingForAProductWithoutARestockDate(): void
    {
        $this->assertNull($this->viewModel->getRestockDate($this->product(null)));
        $this->assertSame('', $this->viewModel->getMessage($this->product(null)));
    }

    public function testSaysNothingWithoutAProduct(): void
    {
        $this->assertNull($this->viewModel->getRestockDate(null));
        $this->assertSame('', $this->viewModel->getMessage(null));
    }

    /**
     * The date is the whole rule, and it is the same rule the Coming Soon adapter selects on — a
     * page still promising a date for a product the category dropped this morning is the way this
     * feature is normally broken.
     */
    public function testSaysNothingOnceTheDateHasPassed(): void
    {
        $this->assertNull($this->viewModel->getRestockDate($this->product('2026-08-13 00:00:00')));
    }

    /**
     * Today counts as future: a delivery landing this afternoon should not vanish from the page at
     * midnight.
     */
    public function testTodayStillCounts(): void
    {
        $this->assertNotNull($this->viewModel->getRestockDate($this->product('2026-08-14 00:00:00')));
    }

    public function testSaysNothingWhenTheMerchantHasClearedTheMessage(): void
    {
        $this->config->method('getArrivalMessage')->willReturn('');

        $this->assertSame('', $this->viewModel->getMessage($this->product('2026-08-20 00:00:00')));
    }

    public function testSaysNothingForAnUnparseableStoredValue(): void
    {
        $this->assertNull($this->viewModel->getRestockDate($this->product('not a date')));
    }

    /**
     * @dataProvider distanceProvider
     */
    public function testTheDaysTokenReadsAsADuration(string $restockDate, string $expected): void
    {
        $this->config->method('getArrivalMessage')->willReturn('{days}');

        $this->assertSame($expected, $this->viewModel->getMessage($this->product($restockDate)));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function distanceProvider(): array
    {
        return [
            'today' => ['2026-08-14 00:00:00', 'today'],
            'tomorrow' => ['2026-08-15 00:00:00', 'in 1 day'],
            'next week' => ['2026-08-21 00:00:00', 'in 7 days'],
        ];
    }

    /**
     * An unknown token is left in the string on purpose: a visible mistake in the admin's own copy
     * is recoverable, a silently dropped one is a sentence that stops making sense and nobody can
     * explain.
     */
    public function testLeavesUnknownTokensAlone(): void
    {
        $this->config->method('getArrivalMessage')->willReturn('Back {soon} — {days}.');

        $this->assertSame('Back {soon} — in 7 days.', $this->viewModel->getMessage($this->product('2026-08-21 00:00:00')));
    }

    public function testTheStoredValueIsReadAsWallClockTimeInTheConfiguredZone(): void
    {
        $restockDate = $this->viewModel->getRestockDate($this->product('2026-08-20 00:00:00'));

        $this->assertNotNull($restockDate);
        $this->assertSame('2026-08-20', $restockDate->format('Y-m-d'));
        $this->assertSame(self::TIMEZONE, $restockDate->getTimezone()->getName());
    }

    private function product(?string $restockDate): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')
            ->willReturnCallback(
                static fn (string $key): mixed => $key === ComingSoon::ATTRIBUTE_CODE ? $restockDate : null
            );

        return $product;
    }

    /**
     * `formatDate` and `formatDateTime` are stubbed rather than exercised: they wrap `IntlDateFormatter`,
     * whose output depends on the ICU version installed, and asserting on it would make the suite fail
     * on a machine that has done nothing wrong.
     */
    private function timezone(): TimezoneInterface&MockObject
    {
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn(self::TIMEZONE);
        $timezone->method('date')->willReturnCallback(
            static fn (): \DateTime => new \DateTime(self::TODAY, new \DateTimeZone(self::TIMEZONE))
        );
        $timezone->method('formatDate')->willReturnCallback(
            static fn (\DateTimeInterface $date): string => $date->format('Y-m-d')
        );
        $timezone->method('formatDateTime')->willReturnCallback(
            static fn (\DateTimeInterface $date): string => $date->format('l')
        );

        return $timezone;
    }
}
