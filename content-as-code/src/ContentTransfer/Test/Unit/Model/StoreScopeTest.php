<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Test\Unit\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Scr1be\ContentTransfer\Model\StoreScope;

class StoreScopeTest extends TestCase
{
    public function testStoreZeroIsWrittenAsNoStoresAtAll(): void
    {
        // `cms_page_store` / `cms_block_store` use store id 0 for "All Store Views", and store 0
        // has no code. The empty list is the bundle's spelling of it.
        $this->assertSame([], $this->scope()->toCodes([0]));
    }

    public function testAllStoreViewsWinsOverAnyOtherAssignment(): void
    {
        $this->assertSame([], $this->scope()->toCodes([2, 0]));
    }

    public function testStoreIdsBecomeSortedCodes(): void
    {
        // Sorted, so a bundle key built from them does not depend on the order the assignment rows
        // came back in.
        $this->assertSame(['de', 'default'], $this->scope()->toCodes([2, 1]));
    }

    public function testAStoreDeletedAfterTheAssignmentIsDropped(): void
    {
        // A dangling `cms_page_store` row outlives its store view. There is no code to write down,
        // so the assignment cannot travel — and stopping the whole capture over it would be worse.
        $this->assertSame(['default'], $this->scope()->toCodes([1, 99]));
    }

    public function testNoCodesMeansStoreZero(): void
    {
        $this->assertSame([0], $this->scope()->toIds([]));
    }

    public function testCodesBecomeIds(): void
    {
        $this->assertSame([1, 2], $this->scope()->toIds(['default', 'de']));
    }

    public function testARepeatedCodeIsNotAssignedTwice(): void
    {
        $this->assertSame([1], $this->scope()->toIds(['default', 'default']));
    }

    public function testACodeThisInstallDoesNotHaveThrows(): void
    {
        // Guessing a store view here would put a bundle's German homepage on the French storefront.
        $this->expectException(NoSuchEntityException::class);

        $this->scope()->toIds(['nope']);
    }

    private function scope(): StoreScope
    {
        $stores = [
            1 => $this->store(1, 'default'),
            2 => $this->store(2, 'de'),
        ];

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(
            static function ($storeIdOrCode) use ($stores): StoreInterface {
                foreach ($stores as $store) {
                    if ($store->getId() === $storeIdOrCode || $store->getCode() === $storeIdOrCode) {
                        return $store;
                    }
                }

                throw new NoSuchEntityException(__('No such store.'));
            }
        );
        $storeManager->method('getStores')->willReturn($stores);

        return new StoreScope($storeManager);
    }

    private function store(int $id, string $code): StoreInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);

        return $store;
    }
}
