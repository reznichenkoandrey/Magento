<?php
declare(strict_types=1);

namespace Scr1be\AdminGridToolkit\Test\Unit\Plugin\Sales;

use Magento\Backend\Model\Session\Quote as QuoteSession;
use Magento\Sales\Model\AdminOrder\Create;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\AdminGridToolkit\Model\Config;
use Scr1be\AdminGridToolkit\Plugin\Sales\MintFreshIncrementIdOnReorder;

class MintFreshIncrementIdOnReorderTest extends TestCase
{
    private const REORDERED = 'reordered';
    private const ORDER_ID = 'order_id';

    /**
     * Stands in for the session storage the backend quote session reads and writes through.
     *
     * @var array<string, mixed>
     */
    private array $sessionData = [];

    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('isReorderIncrementIdFixEnabled')->willReturn(true);
    }

    /**
     * The whole point: core's beforeSubmit() must not find the flag, because finding it is what
     * sends the new order down the "<original>-1" edit path.
     */
    public function testCoreSubmitsAReorderWithoutTheFlag(): void
    {
        $this->sessionData = [self::REORDERED => 15];

        $result = $this->plugin()->aroundCreateOrder($this->subject(), $this->proceedRecording($seen));

        $this->assertSame('order', $result);
        $this->assertNull($seen, 'core saw the reorder flag while submitting');
        $this->assertSame(15, $this->sessionData[self::REORDERED], 'the flag was not restored');
    }

    /**
     * An order edit is the case core's lineage was written for: the new order really is a revision
     * of the old one, and the module has no business touching it.
     */
    public function testAnOrderEditKeepsCoreLineage(): void
    {
        $this->sessionData = [self::ORDER_ID => 15];

        $this->plugin()->aroundCreateOrder($this->subject(), $this->proceedRecording($seen, self::ORDER_ID));

        $this->assertSame(15, $seen);
    }

    /**
     * A session carrying both keys cannot happen — each controller clears the storage before it
     * writes one — but if it did, core reads the edit's order first, so the flag is irrelevant and
     * removing it would be a change with no reason behind it.
     */
    public function testASessionCarryingBothKeysIsLeftToCore(): void
    {
        $this->sessionData = [self::REORDERED => 15, self::ORDER_ID => 16];

        $this->plugin()->aroundCreateOrder($this->subject(), $this->proceedRecording($seen));

        $this->assertSame(15, $seen);
    }

    public function testAPlainAdminOrderIsUntouched(): void
    {
        $this->sessionData = [];

        $this->plugin()->aroundCreateOrder($this->subject(), $this->proceedRecording($seen));

        $this->assertNull($seen);
        $this->assertSame([], $this->sessionData);
    }

    public function testTheFixCanBeSwitchedOff(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isReorderIncrementIdFixEnabled')->willReturn(false);
        $this->sessionData = [self::REORDERED => 15];

        (new MintFreshIncrementIdOnReorder($config))
            ->aroundCreateOrder($this->subject(), $this->proceedRecording($seen));

        $this->assertSame(15, $seen, 'switched off has to mean core behaviour');
    }

    /**
     * The restore is what keeps a failed save recoverable: the next request resolves the admin's
     * ACL resource from this flag, and a role holding reorder but not create would be locked out of
     * retrying its own reorder.
     */
    public function testTheFlagComesBackWhenTheSubmitThrows(): void
    {
        $this->sessionData = [self::REORDERED => 15];

        try {
            $this->plugin()->aroundCreateOrder(
                $this->subject(),
                static function (): never {
                    throw new \RuntimeException('payment declined');
                }
            );
            $this->fail('the exception was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('payment declined', $e->getMessage());
        }

        $this->assertSame(15, $this->sessionData[self::REORDERED]);
    }

    private function plugin(): MintFreshIncrementIdOnReorder
    {
        return new MintFreshIncrementIdOnReorder($this->config);
    }

    /**
     * Records what the session held at the moment core would have read it.
     */
    private function proceedRecording(mixed &$seen, string $key = self::REORDERED): callable
    {
        $seen = null;

        return function () use (&$seen, $key): string {
            $seen = $this->sessionData[$key] ?? null;

            return 'order';
        };
    }

    private function subject(): Create&MockObject
    {
        // getData() is a real method on the session manager; setData() and unsetData() reach the
        // storage through __call, which is also how core writes this flag in the first place.
        $session = $this->createMock(QuoteSession::class);
        $session->method('getData')->willReturnCallback(
            fn (string $key): mixed => $this->sessionData[$key] ?? null
        );
        $session->method('__call')->willReturnCallback(
            function (string $method, array $arguments): mixed {
                return match ($method) {
                    'setData' => $this->sessionData[$arguments[0]] = $arguments[1],
                    'unsetData' => $this->forget($arguments[0]),
                    default => null,
                };
            }
        );

        $subject = $this->createMock(Create::class);
        $subject->method('getSession')->willReturn($session);

        return $subject;
    }

    private function forget(string $key): mixed
    {
        unset($this->sessionData[$key]);

        return null;
    }
}
