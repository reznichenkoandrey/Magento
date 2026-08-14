<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\BackInStock\Model\QtyRules;

class QtyRulesTest extends TestCase
{
    public function testAProductWithNoRulesStartsAtOne(): void
    {
        $this->assertSame(1.0, QtyRules::unrestricted()->getStartQty());
    }

    public function testTheMinimumIsTheStartingQuantity(): void
    {
        $this->assertSame(6.0, (new QtyRules(6.0, 0.0, 0.0, false))->getStartQty());
    }

    public function testAMinimumOffTheIncrementIsRoundedUpToTheNextSellableQuantity(): void
    {
        // A product with a minimum of 3 and an increment of 2 does not sell 3. Starting the stepper
        // there means the first thing the customer sees is a quantity the cart will refuse.
        $this->assertSame(4.0, (new QtyRules(3.0, 0.0, 2.0, false))->getStartQty());
    }

    public function testAnAlreadyValidMinimumIsLeftWhereItIs(): void
    {
        $this->assertSame(4.0, (new QtyRules(4.0, 0.0, 2.0, false))->getStartQty());
    }

    public function testWholeUnitProductsNeverStartOnAFraction(): void
    {
        $this->assertSame(2.0, (new QtyRules(1.5, 0.0, 0.0, false))->getStartQty());
    }

    public function testDecimalProductsKeepTheirFraction(): void
    {
        $this->assertSame(1.5, (new QtyRules(1.5, 0.0, 0.0, true))->getStartQty());
    }

    public function testTheArrayFormCarriesTheStartQuantityTheStepperNeeds(): void
    {
        // The template reads `item.qty.start` for its floor, so it has to be in the payload rather
        // than recomputed in JavaScript from min and increment.
        $this->assertSame(
            ['min' => 3.0, 'max' => 10.0, 'increment' => 2.0, 'decimal' => false, 'start' => 4.0],
            (new QtyRules(3.0, 10.0, 2.0, false))->toArray()
        );
    }
}
