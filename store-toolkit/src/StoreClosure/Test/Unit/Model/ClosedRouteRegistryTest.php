<?php
declare(strict_types=1);

namespace Scr1be\StoreClosure\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\StoreClosure\Model\ClosedRouteRegistry;

class ClosedRouteRegistryTest extends TestCase
{
    private ClosedRouteRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ClosedRouteRegistry(
            ['checkout', 'multishipping'],
            ['customer_account_login', 'customer_account_loginpost']
        );
    }

    public function testAWholeRouteCloses(): void
    {
        self::assertTrue($this->registry->isClosedRoute('checkout', 'checkout_cart_index'));
        self::assertTrue($this->registry->isClosedRoute('checkout', 'checkout_index_index'));
    }

    public function testASingleActionCloses(): void
    {
        self::assertTrue($this->registry->isClosedRoute('customer', 'customer_account_login'));
    }

    public function testActionMatchingIsCaseInsensitive(): void
    {
        // Magento\Framework\App\Request\Http::getFullActionName() concatenates the router's own
        // strings verbatim, so the real request carries `customer_account_loginPost`.
        self::assertTrue($this->registry->isClosedRoute('customer', 'customer_account_loginPost'));
        self::assertTrue($this->registry->isClosedRoute('CHECKOUT', 'checkout_cart_index'));
    }

    public function testTheRestOfTheCustomerRouteStaysOpen(): void
    {
        // A signed-in customer keeps their dashboard and their order history during a closure.
        self::assertFalse($this->registry->isClosedRoute('customer', 'customer_account_index'));
        self::assertFalse($this->registry->isClosedRoute('sales', 'sales_order_history'));
    }

    public function testTheStoreSwitcherEndpointsAreNeverClosed(): void
    {
        // The one route a closed store must keep: it is how a visitor reaches an open sibling.
        self::assertFalse($this->registry->isClosedRoute('stores', 'stores_store_redirect'));
        self::assertFalse($this->registry->isClosedRoute('stores', 'stores_store_switch'));
    }

    public function testAnEmptyRegistryClosesNothing(): void
    {
        self::assertFalse((new ClosedRouteRegistry())->isClosedRoute('checkout', 'checkout_index_index'));
    }
}
