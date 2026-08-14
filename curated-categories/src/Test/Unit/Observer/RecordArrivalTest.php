<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Test\Unit\Observer;

use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CuratedCategories\Api\CurationEngineInterface;
use Scr1be\CuratedCategories\Model\CurationLog;
use Scr1be\CuratedCategories\Model\CurationResult;
use Scr1be\CuratedCategories\Model\CurationTarget;
use Scr1be\CuratedCategories\Model\ResourceModel\ArrivalIndex;
use Scr1be\CuratedCategories\Model\Source\NewArrivals;
use Scr1be\CuratedCategories\Observer\RecordArrival;

class RecordArrivalTest extends TestCase
{
    private const NOW_UTC = '2026-08-14 09:00:00';

    private NewArrivals&MockObject $newArrivals;
    private CurationEngineInterface&MockObject $engine;
    private ArrivalIndex&MockObject $arrivalIndex;
    private CurationLog&MockObject $log;
    private RecordArrival $observer;

    protected function setUp(): void
    {
        $this->newArrivals = $this->createMock(NewArrivals::class);
        $this->engine = $this->createMock(CurationEngineInterface::class);
        $this->arrivalIndex = $this->createMock(ArrivalIndex::class);
        $this->log = $this->createMock(CurationLog::class);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn(self::NOW_UTC);

        $this->observer = new RecordArrival(
            $this->newArrivals,
            $this->engine,
            $this->arrivalIndex,
            $dateTime,
            $this->log
        );
    }

    public function testStampsAndAddsOnAProductsFirstArrival(): void
    {
        $this->arrivalIndex->method('getArrivalDate')->willReturn(null);
        $this->arrivalIndex->expects($this->once())->method('recordArrival')->with(77, self::NOW_UTC);

        $target = new CurationTarget(9, 4, NewArrivals::CODE);
        $this->newArrivals->method('isEnabled')->willReturn(true);
        $this->newArrivals->method('getTarget')->willReturn($target);
        $this->newArrivals->method('qualifies')->willReturn(true);

        $this->engine->expects($this->once())
            ->method('add')
            ->with($target, [77])
            ->willReturn(CurationResult::of($target, [77], [], [], [], false));

        $this->observer->execute($this->observerFor(true, 77));
    }

    /**
     * Order placement writes the stock item on the checkout path. After the first arrival there is
     * nothing here worth a category read, and the hourly reconcile owns the membership from then on.
     */
    public function testDoesNothingForAProductThatHasArrivedBefore(): void
    {
        $this->arrivalIndex->method('getArrivalDate')->willReturn('2026-01-01 00:00:00');
        $this->arrivalIndex->expects($this->never())->method('recordArrival');
        $this->engine->expects($this->never())->method('add');

        $this->observer->execute($this->observerFor(true, 77));
    }

    public function testIgnoresAStockItemThatIsNotInStock(): void
    {
        $this->arrivalIndex->expects($this->never())->method('getArrivalDate');

        $this->observer->execute($this->observerFor(false, 77));
    }

    public function testIgnoresAnEventCarryingSomethingElse(): void
    {
        $this->arrivalIndex->expects($this->never())->method('getArrivalDate');

        $event = new Event(['item' => new \stdClass()]);
        $this->observer->execute(new Observer(['event' => $event]));
    }

    public function testIgnoresAStockItemWithNoProduct(): void
    {
        $this->arrivalIndex->expects($this->never())->method('getArrivalDate');

        $this->observer->execute($this->observerFor(true, 0));
    }

    /**
     * The arrival is still stamped even when the adapter is switched off — the log is the record of
     * when products became buyable, and it has to be complete on the day someone turns the feature
     * on.
     */
    public function testStampsTheArrivalEvenWhenTheAdapterIsDisabled(): void
    {
        $this->arrivalIndex->method('getArrivalDate')->willReturn(null);
        $this->arrivalIndex->expects($this->once())->method('recordArrival');

        $this->newArrivals->method('isEnabled')->willReturn(false);
        $this->engine->expects($this->never())->method('add');

        $this->observer->execute($this->observerFor(true, 77));
    }

    public function testDoesNotAddAProductTheExclusionRulesReject(): void
    {
        $this->arrivalIndex->method('getArrivalDate')->willReturn(null);
        $this->newArrivals->method('isEnabled')->willReturn(true);
        $this->newArrivals->method('getTarget')->willReturn(new CurationTarget(9, 4, NewArrivals::CODE));
        $this->newArrivals->method('qualifies')->willReturn(false);

        $this->engine->expects($this->never())->method('add');

        $this->observer->execute($this->observerFor(true, 77));
    }

    /**
     * A commit-after observer that throws turns a completed stock save into an error page, and on
     * the ERP path into a failed API call for a write that already succeeded.
     */
    public function testSwallowsAndLogsAFailure(): void
    {
        $this->arrivalIndex->method('getArrivalDate')
            ->willThrowException(new \RuntimeException('deadlock'));

        $this->log->expects($this->once())->method('logFailure')
            ->with(NewArrivals::CODE, $this->isInstanceOf(\RuntimeException::class));

        $this->observer->execute($this->observerFor(true, 77));
    }

    private function observerFor(bool $inStock, int $productId): Observer
    {
        $item = $this->createMock(StockItemInterface::class);
        $item->method('getIsInStock')->willReturn($inStock);
        $item->method('getProductId')->willReturn($productId);

        return new Observer(['event' => new Event(['item' => $item])]);
    }
}
