<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Test\Unit\Plugin;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\QuoteGraphQl\Model\Resolver\PlaceOrder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\OrderAttribution\Model\Attribution;
use Scr1be\OrderAttribution\Model\AttributionHolder;
use Scr1be\OrderAttribution\Model\SourceValidator;
use Scr1be\OrderAttribution\Model\UnknownSourceException;
use Scr1be\OrderAttribution\Plugin\CaptureAttribution;

/**
 * The plugin's contract is a window: the attribution is current exactly while core runs, and never
 * after. Both halves are asserted, including the half that only matters when core throws.
 */
class CaptureAttributionTest extends TestCase
{
    private SourceValidator&MockObject $validator;
    private AttributionHolder $holder;
    private CaptureAttribution $plugin;
    private PlaceOrder&MockObject $subject;
    private Field&MockObject $field;
    private ResolveInfo&MockObject $info;

    protected function setUp(): void
    {
        $this->validator = $this->createMock(SourceValidator::class);
        $this->holder = new AttributionHolder();
        $this->plugin = new CaptureAttribution($this->validator, $this->holder);
        $this->subject = $this->createMock(PlaceOrder::class);
        $this->field = $this->createMock(Field::class);
        $this->info = $this->createMock(ResolveInfo::class);
    }

    public function testTheAttributionIsCurrentWhileCoreRuns(): void
    {
        $this->validator->method('validate')->willReturn(Attribution::of('ios-app', 'build 412'));

        $seen = null;
        $this->call(
            ['input' => ['cart_id' => 'abc', 'order_source' => ['source_code' => 'ios-app']]],
            function () use (&$seen) {
                $seen = $this->holder->current();

                return ['order' => ['order_number' => '1']];
            }
        );

        $this->assertSame('ios-app', $seen?->sourceCode);
        $this->assertNull($this->holder->current(), 'The window must close when core returns');
    }

    /**
     * Without the `finally`, a failed order would leave its source current and the *next* order in
     * the same request would be attributed to it.
     */
    public function testTheWindowClosesEvenWhenCoreThrows(): void
    {
        $this->validator->method('validate')->willReturn(Attribution::of('ios-app', null));

        try {
            $this->call(
                ['input' => ['cart_id' => 'abc', 'order_source' => ['source_code' => 'ios-app']]],
                static fn () => throw new \RuntimeException('payment declined')
            );
            $this->fail('The exception must propagate');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull($this->holder->current());
    }

    public function testCoreRunsUntouchedWhenNoSourceWasSent(): void
    {
        $this->validator->method('validate')->with(null)->willReturn(null);

        $result = $this->call(
            ['input' => ['cart_id' => 'abc']],
            fn () => ['order' => ['order_number' => '1'], 'seen' => $this->holder->current()]
        );

        $this->assertNull($result['seen']);
        $this->assertSame('1', $result['order']['order_number']);
    }

    /**
     * A refusal must happen before core runs: placing the order and then complaining about the
     * source would leave the shopper charged and the mutation reporting an error.
     */
    public function testAnUnknownSourceStopsCoreFromRunning(): void
    {
        $this->validator->method('validate')->willThrowException(new UnknownSourceException('made-up'));

        $ran = false;
        $this->expectException(UnknownSourceException::class);

        try {
            $this->call(
                ['input' => ['cart_id' => 'abc', 'order_source' => ['source_code' => 'made-up']]],
                static function () use (&$ran) {
                    $ran = true;

                    return [];
                }
            );
        } finally {
            $this->assertFalse($ran, 'The order must not be placed');
        }
    }

    /**
     * `order_source` arriving as something other than an object is a client bug, not a reason to
     * hand the validator a string it will index into.
     */
    public function testANonObjectSourceIsTreatedAsAbsent(): void
    {
        $this->validator->expects($this->once())->method('validate')->with(null)->willReturn(null);

        $this->call(
            ['input' => ['cart_id' => 'abc', 'order_source' => 'ios-app']],
            static fn () => ['order' => ['order_number' => '1']]
        );
    }

    /**
     * @param array<string, mixed> $args
     * @param callable $proceed
     * @return mixed
     */
    private function call(array $args, callable $proceed)
    {
        return $this->plugin->aroundResolve(
            $this->subject,
            $proceed,
            $this->field,
            null,
            $this->info,
            null,
            $args
        );
    }
}
