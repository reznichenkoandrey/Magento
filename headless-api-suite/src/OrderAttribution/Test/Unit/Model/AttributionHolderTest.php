<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Scr1be\OrderAttribution\Model\Attribution;
use Scr1be\OrderAttribution\Model\AttributionHolder;

/**
 * The holder is four lines of code and the whole module's correctness rests on them.
 */
class AttributionHolderTest extends TestCase
{
    private AttributionHolder $holder;

    protected function setUp(): void
    {
        $this->holder = new AttributionHolder();
    }

    public function testStartsEmpty(): void
    {
        $this->assertNull($this->holder->current());
    }

    public function testCurrentIsTheOneMostRecentlyPushed(): void
    {
        $this->holder->push(Attribution::of('web', null));
        $this->holder->push(Attribution::of('ios-app', 'build 1'));

        $this->assertSame('ios-app', $this->holder->current()?->sourceCode);
    }

    /**
     * Two placeOrder mutations in one GraphQL document. They execute serially, so as long as the
     * plugin pops in a `finally`, the second order must never see the first order's source.
     */
    public function testPoppingRestoresTheEmptyState(): void
    {
        $this->holder->push(Attribution::of('web', null));
        $this->holder->pop();

        $this->assertNull($this->holder->current());

        $this->holder->push(Attribution::of('ios-app', null));
        $this->assertSame('ios-app', $this->holder->current()?->sourceCode);
    }

    public function testPoppingAnEmptyStackIsHarmless(): void
    {
        $this->holder->pop();

        $this->assertNull($this->holder->current());
    }

    public function testResetStateEmptiesTheStack(): void
    {
        $this->holder->push(Attribution::of('web', null));
        $this->holder->push(Attribution::of('web', null));

        $this->holder->_resetState();

        $this->assertNull($this->holder->current());
    }
}
