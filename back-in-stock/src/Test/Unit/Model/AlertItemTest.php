<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use PHPUnit\Framework\TestCase;
use Scr1be\BackInStock\Model\AlertItem;
use Scr1be\BackInStock\Model\AlertState;
use Scr1be\BackInStock\Model\QtyRules;

class AlertItemTest extends TestCase
{
    public function testASimpleSalableProductCanBeAddedFromTheCard(): void
    {
        $this->assertTrue($this->item(true, false)->isAddToCartable());
    }

    public function testAConfigurableIsSentToItsProductPageInstead(): void
    {
        // The card does not know which variant, and a `checkout/cart/add` without a
        // `super_attribute` map comes back as an error message rather than a cart line.
        $this->assertFalse($this->item(true, true)->isAddToCartable());
    }

    public function testAProductThatSoldOutAgainCannotBeAdded(): void
    {
        // The popup's data is as old as the last customer-data fetch. Between then and the click the
        // restock may have been bought out by somebody else.
        $this->assertFalse($this->item(false, false)->isAddToCartable());
    }

    public function testADiscountNeedsBothPrices(): void
    {
        $this->assertTrue($this->item(true, false, 8.0, 10.0)->isDiscounted());
        $this->assertFalse($this->item(true, false, 10.0, 10.0)->isDiscounted());
    }

    public function testAProductMissingFromThePriceIndexIsNotOnSale(): void
    {
        // A zero regular price is an absent index row, not a giveaway, and `0 > final` would render
        // a struck-through "£0.00" beside the real price.
        $this->assertFalse($this->item(true, false, 8.0, 0.0)->isDiscounted());
    }

    private function item(
        bool $salable,
        bool $requiresConfiguration,
        float $final = 10.0,
        float $regular = 10.0
    ): AlertItem {
        return new AlertItem(
            1,
            $this->createMock(Product::class),
            AlertState::ALERT_SENT,
            AlertState::POPUP_QUEUED,
            '2026-05-01 09:00:00',
            '2026-06-01 09:00:00',
            $final,
            $regular,
            80,
            3,
            $salable,
            $requiresConfiguration,
            QtyRules::unrestricted(),
            []
        );
    }
}
