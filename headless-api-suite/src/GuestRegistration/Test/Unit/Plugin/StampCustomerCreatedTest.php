<?php
declare(strict_types=1);

namespace Scr1be\GuestRegistration\Test\Unit\Plugin;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\QuoteGraphQl\Model\Resolver\PlaceOrder;
use Magento\QuoteGraphQl\Model\Resolver\SetPaymentAndPlaceOrder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\GuestRegistration\Model\RegistrationOutcome;
use Scr1be\GuestRegistration\Model\RegistrationResultHolder;
use Scr1be\GuestRegistration\Plugin\StampCustomerCreated;

/**
 * The plugin reads one key out of core's return value and must survive every shape core can produce.
 *
 * The shapes asserted here are the two branches of
 * `Magento\QuoteGraphQl\Model\Resolver\PlaceOrder::resolve()`: `['errors' => [...]]` when placing
 * failed, and `['order' => ['order_number' => ..., 'order_id' => ...], 'orderV2' => ..., 'errors' =>
 * []]` when it succeeded.
 */
class StampCustomerCreatedTest extends TestCase
{
    private RegistrationResultHolder $holder;
    private StampCustomerCreated $plugin;
    private ResolverInterface&MockObject $subject;
    private Field&MockObject $field;
    private ResolveInfo&MockObject $info;

    protected function setUp(): void
    {
        $this->holder = new RegistrationResultHolder();
        $this->plugin = new StampCustomerCreated($this->holder);
        $this->subject = $this->createMock(PlaceOrder::class);
        $this->field = $this->createMock(Field::class);
        $this->info = $this->createMock(ResolveInfo::class);
    }

    public function testStampsTrueWhenAnAccountWasCreated(): void
    {
        $this->holder->record('000000123', RegistrationOutcome::CREATED);

        $result = $this->call($this->successResult('000000123'));

        $this->assertTrue($result['customer_created']);
    }

    public function testStampsFalseWhenTheOrderWasOnlyLinked(): void
    {
        $this->holder->record('000000123', RegistrationOutcome::LINKED_EXISTING);

        $result = $this->call($this->successResult('000000123'));

        $this->assertFalse(
            $result['customer_created'],
            'Linking is not creating: the app must not offer a password prompt to somebody who has one'
        );
    }

    /**
     * Null rather than false, so the client can tell "no order" from "order, no account".
     */
    public function testLeavesTheErrorShapeAlone(): void
    {
        $this->holder->record('000000123', RegistrationOutcome::CREATED);

        $result = $this->call(['errors' => [['message' => 'nope', 'code' => 'CART_NOT_FOUND']]]);

        $this->assertArrayNotHasKey('customer_created', $result);
    }

    public function testLeavesTheResultAloneWhenTheObserverNeverRan(): void
    {
        $result = $this->call($this->successResult('000000123'));

        $this->assertArrayNotHasKey('customer_created', $result);
    }

    public function testPassesThroughANonArrayResult(): void
    {
        $this->assertNull($this->call(null));
    }

    /**
     * The deprecated `setPaymentMethodAndPlaceOrder` resolver returns the same success shape and is
     * plugged separately, so the plugin must accept it as a subject.
     */
    public function testWorksForTheDeprecatedPlaceOrderResolverToo(): void
    {
        $this->holder->record('000000123', RegistrationOutcome::CREATED);
        $this->subject = $this->createMock(SetPaymentAndPlaceOrder::class);

        $this->assertTrue($this->call($this->successResult('000000123'))['customer_created']);
    }

    /**
     * Two orders in one request must not report each other's outcome.
     */
    public function testAnswersPerOrderWithinOneRequest(): void
    {
        $this->holder->record('000000123', RegistrationOutcome::CREATED);
        $this->holder->record('000000124', RegistrationOutcome::SKIPPED_LOGGED_IN);

        $this->assertTrue($this->call($this->successResult('000000123'))['customer_created']);
        $this->assertFalse($this->call($this->successResult('000000124'))['customer_created']);
    }

    /**
     * @param string $incrementId
     * @return array<string, mixed>
     */
    private function successResult(string $incrementId): array
    {
        return [
            'order' => ['order_number' => $incrementId, 'order_id' => $incrementId],
            'orderV2' => ['id' => 'abc'],
            'errors' => [],
        ];
    }

    /**
     * @param array<string, mixed>|null $result
     * @return array<string, mixed>|null
     */
    private function call(?array $result): ?array
    {
        return $this->plugin->afterResolve($this->subject, $result, $this->field, null, $this->info, null, []);
    }
}
