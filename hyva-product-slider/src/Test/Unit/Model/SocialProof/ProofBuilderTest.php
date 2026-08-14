<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Test\Unit\Model\SocialProof;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\HyvaProductSlider\Model\Config;
use Scr1be\HyvaProductSlider\Model\ResourceModel\PurchaseIndex;
use Scr1be\HyvaProductSlider\Model\SocialProof\ProofBuilder;
use Scr1be\HyvaProductSlider\Model\SocialProof\RelativeTime;

class ProofBuilderTest extends TestCase
{
    /** Fixed "now", so the elapsed arithmetic is a value and not a race with the clock. */
    private const NOW = '2026-08-14 12:00:00';

    private PurchaseIndex&MockObject $purchaseIndex;
    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->purchaseIndex = $this->createMock(PurchaseIndex::class);

        $this->config = $this->createMock(Config::class);
        $this->config->method('isSocialProofEnabled')->willReturn(true);
        $this->config->method('getSocialProofWindowHours')->willReturn(72);
        $this->config->method('isBuyerNameShown')->willReturn(true);
        $this->config->method('isBuyerCityShown')->willReturn(true);
    }

    public function testTheGlobalSwitchStopsEverythingBeforeAQueryIsIssued(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isSocialProofEnabled')->willReturn(false);

        $this->purchaseIndex->expects($this->never())->method('getPurchases');

        $this->assertSame([], $this->builder($config)->build([1], 1));
    }

    public function testAnEmptyProductListNeverReachesTheIndex(): void
    {
        $this->purchaseIndex->expects($this->never())->method('getPurchases');

        $this->assertSame([], $this->builder()->build([], 1));
    }

    public function testNameAndCityAreBothUsedWhenBothAreAllowed(): void
    {
        $this->stubPurchases([1 => $this->row('2026-08-14 11:43:00', 'Anna', 'Austin')]);

        $this->assertSame('17 minutes ago, Anna from Austin bought this', $this->text(1));
    }

    public function testTheCityIsDroppedWhenTheStoreTurnedItOff(): void
    {
        $config = $this->configWith(['city' => false]);
        $this->stubPurchases([1 => $this->row('2026-08-14 11:43:00', 'Anna', 'Austin')]);

        $this->assertSame(
            '17 minutes ago, Anna bought this',
            $this->builder($config)->build([1], 1)[1]->getText()
        );
    }

    public function testTheNameIsDroppedWhenTheStoreTurnedItOff(): void
    {
        $config = $this->configWith(['name' => false]);
        $this->stubPurchases([1 => $this->row('2026-08-14 11:43:00', 'Anna', 'Austin')]);

        $this->assertSame(
            '17 minutes ago, someone from Austin bought this',
            $this->builder($config)->build([1], 1)[1]->getText()
        );
    }

    public function testWithNeitherHalfTheLineStillSaysThatItHappened(): void
    {
        // The useful part of social proof is the event, not the identity.
        $config = $this->configWith(['name' => false, 'city' => false]);
        $this->stubPurchases([1 => $this->row('2026-08-14 11:43:00', 'Anna', 'Austin')]);

        $this->assertSame(
            '17 minutes ago, someone bought this',
            $this->builder($config)->build([1], 1)[1]->getText()
        );
    }

    public function testOnlyTheFirstTokenOfAFirstNameIsShown(): void
    {
        // `customer_firstname` is free text and routinely holds a full name. A surname typed into
        // the wrong box must not end up on a public page.
        $this->stubPurchases([1 => $this->row('2026-08-14 11:43:00', 'Anna Marie Kowalski', 'Austin')]);

        $this->assertStringContainsString('Anna from', $this->text(1));
        $this->assertStringNotContainsString('Kowalski', $this->text(1));
    }

    public function testACityKeepsAllOfItsWords(): void
    {
        // The mirror image of the rule above: "New York" must not become "New".
        $this->stubPurchases([1 => $this->row('2026-08-14 11:43:00', 'Anna', 'New York')]);

        $this->assertStringContainsString('from New York', $this->text(1));
    }

    public function testABlankNameFallsBackToTheAnonymousWording(): void
    {
        $this->stubPurchases([1 => $this->row('2026-08-14 11:43:00', '   ', null)]);

        $this->assertSame('17 minutes ago, someone bought this', $this->text(1));
    }

    public function testTheStoredTimestampIsReadAsUtc(): void
    {
        // The store's clock here is UTC+2. Reading `last_ordered_at` as a local wall clock would
        // report a two-hour-old purchase as brand new, and would be invisible on a UTC shop.
        $this->stubPurchases([1 => $this->row('2026-08-14 09:00:00', 'Anna', 'Austin')]);

        $builder = $this->builder(null, new \DateTimeZone('+02:00'));

        // 12:00+02:00 is 10:00Z, one hour after the stored 09:00Z.
        $this->assertSame('an hour ago, Anna from Austin bought this', $builder->build([1], 1)[1]->getText());
    }

    public function testTheWindowIsPassedToTheIndexAsAUtcBoundary(): void
    {
        $this->purchaseIndex->expects($this->once())
            ->method('getPurchases')
            ->with(1, [1], '2026-08-11 12:00:00')
            ->willReturn([]);

        $this->builder()->build([1], 1);
    }

    public function testThePurchaseCountTravelsAlongsideTheSentence(): void
    {
        $this->stubPurchases([1 => $this->row('2026-08-14 11:43:00', 'Anna', 'Austin', 4)]);

        $proof = $this->builder()->build([1], 1)[1];

        $this->assertSame(4, $proof->getPurchases());
        $this->assertSame(1020, $proof->getElapsedSeconds());
    }

    public function testTheSerialisedShapeCarriesNothingThatIdentifiesAnOrder(): void
    {
        $this->stubPurchases([1 => $this->row('2026-08-14 11:43:00', 'Anna', 'Austin', 4)]);

        $this->assertSame(
            ['text', 'elapsed', 'purchases'],
            array_keys($this->builder()->build([1], 1)[1]->jsonSerialize())
        );
    }

    /**
     * @param array<int, array{last_ordered_at: string, purchases: int, buyer_name: ?string, buyer_city: ?string}> $rows
     */
    private function stubPurchases(array $rows): void
    {
        $this->purchaseIndex->method('getPurchases')->willReturn($rows);
    }

    /**
     * @return array{last_ordered_at: string, purchases: int, buyer_name: ?string, buyer_city: ?string}
     */
    private function row(string $orderedAt, ?string $name, ?string $city, int $purchases = 1): array
    {
        return [
            'last_ordered_at' => $orderedAt,
            'purchases' => $purchases,
            'buyer_name' => $name,
            'buyer_city' => $city,
        ];
    }

    private function text(int $productId): string
    {
        return $this->builder()->build([$productId], 1)[$productId]->getText();
    }

    /**
     * @param array{name?: bool, city?: bool} $flags
     */
    private function configWith(array $flags): Config&MockObject
    {
        $config = $this->createMock(Config::class);
        $config->method('isSocialProofEnabled')->willReturn(true);
        $config->method('getSocialProofWindowHours')->willReturn(72);
        $config->method('isBuyerNameShown')->willReturn($flags['name'] ?? true);
        $config->method('isBuyerCityShown')->willReturn($flags['city'] ?? true);

        return $config;
    }

    private function builder(?Config $config = null, ?\DateTimeZone $timezone = null): ProofBuilder
    {
        $localeDate = $this->createMock(TimezoneInterface::class);
        $localeDate->method('date')->willReturnCallback(
            static fn (): \DateTime => new \DateTime(self::NOW, $timezone ?? new \DateTimeZone('UTC'))
        );

        return new ProofBuilder(
            $this->purchaseIndex,
            new RelativeTime(),
            $localeDate,
            $config ?? $this->config
        );
    }
}
