<?php
declare(strict_types=1);

namespace Scr1be\HyvaMedia\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\HyvaMedia\Model\Config;
use Scr1be\HyvaMedia\Model\EncodeBudget;

class EncodeBudgetTest extends TestCase
{
    public function testTheBudgetIsSpentAndThenRefused(): void
    {
        $budget = new EncodeBudget($this->configAllowing(3));

        $this->assertTrue($budget->tryConsume());
        $this->assertTrue($budget->tryConsume());
        $this->assertTrue($budget->tryConsume());
        $this->assertFalse($budget->tryConsume());
        $this->assertSame(3, $budget->spent());
    }

    public function testARefusalDoesNotConsumeASlot(): void
    {
        // Otherwise the counter climbs past the ceiling on every subsequent image, which matters
        // the moment the ceiling is ever raised mid-request by a store-scoped read.
        $budget = new EncodeBudget($this->configAllowing(1));

        $budget->tryConsume();
        $budget->tryConsume();
        $budget->tryConsume();

        $this->assertSame(1, $budget->spent());
    }

    public function testAFreshBudgetHasSpentNothing(): void
    {
        $this->assertSame(0, (new EncodeBudget($this->configAllowing(5)))->spent());
    }

    private function configAllowing(int $encodes): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('getMaxEncodesPerRequest')->willReturn($encodes);

        return $config;
    }
}
