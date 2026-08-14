<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\StoreClosure\Model\ClosureState;

class ClosureStateTest extends TestCase
{
    /**
     * @var ScopeConfigInterface&MockObject
     */
    private $scopeConfig;

    /**
     * @var StoreManagerInterface&MockObject
     */
    private $storeManager;

    private ClosureState $state;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->state = new ClosureState($this->scopeConfig, $this->storeManager);
    }

    public function testTheFlagIsReadAtStoreScope(): void
    {
        // Website scope would close every store view of a website at once, which is precisely the
        // thing this module exists not to do.
        $this->scopeConfig->expects(self::once())
            ->method('isSetFlag')
            ->with(ClosureState::XML_PATH_ENABLED, 'store', 2)
            ->willReturn(true);

        self::assertTrue($this->state->isClosed(2));
    }

    public function testPricesAreOnlyHiddenWhenTheStoreIsActuallyClosed(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturnMap([
            [ClosureState::XML_PATH_ENABLED, 'store', 2, false],
            [ClosureState::XML_PATH_HIDE_PRICES, 'store', 2, true],
        ]);

        self::assertFalse($this->state->shouldHidePrices(2));
    }

    public function testPricesAreHiddenWhenBothFlagsAgree(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturnMap([
            [ClosureState::XML_PATH_ENABLED, 'store', 2, true],
            [ClosureState::XML_PATH_HIDE_PRICES, 'store', 2, true],
        ]);

        self::assertTrue($this->state->shouldHidePrices(2));
    }

    public function testAClosedStoreCanStillShowItsPrices(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturnMap([
            [ClosureState::XML_PATH_ENABLED, 'store', 2, true],
            [ClosureState::XML_PATH_HIDE_PRICES, 'store', 2, false],
        ]);

        self::assertFalse($this->state->shouldHidePrices(2));
    }

    public function testAnUnresolvableStoreCountsAsOpen(): void
    {
        // Failing open is the right direction here: refusing to serve a storefront because the
        // store could not be resolved turns a misconfiguration into an outage.
        $this->storeManager->method('getStore')
            ->willThrowException(new NoSuchEntityException(__('No store.')));

        self::assertFalse($this->state->isCurrentStoreClosed());
    }

    public function testTheCurrentStoreIsAskedAboutById(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->scopeConfig->expects(self::once())
            ->method('isSetFlag')
            ->with(ClosureState::XML_PATH_ENABLED, 'store', 3)
            ->willReturn(true);

        self::assertTrue($this->state->isCurrentStoreClosed());
    }
}
