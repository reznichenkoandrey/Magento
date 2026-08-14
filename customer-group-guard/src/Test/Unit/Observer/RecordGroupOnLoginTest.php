<?php
declare(strict_types=1);

namespace Scr1be\CustomerGroupGuard\Test\Unit\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Customer as CustomerModel;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CustomerGroupGuard\Model\Config;
use Scr1be\CustomerGroupGuard\Model\GroupCookie;
use Scr1be\CustomerGroupGuard\Observer\RecordGroupOnLogin;

class RecordGroupOnLoginTest extends TestCase
{
    private Config&MockObject $config;
    private GroupCookie&MockObject $groupCookie;
    private RecordGroupOnLogin $observer;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->groupCookie = $this->createMock(GroupCookie::class);

        $this->config->method('isForceLogoutEnabled')->willReturn(true);

        $this->observer = new RecordGroupOnLogin($this->config, $this->groupCookie);
    }

    public function testRecordsTheGroupCarriedByTheLoginEvent(): void
    {
        $customer = $this->createMock(CustomerModel::class);
        $customer->method('getGroupId')->willReturn('2');

        $this->groupCookie->expects($this->once())->method('write')->with(2);

        $this->observer->execute($this->loginEvent($customer));
    }

    /**
     * Both login paths dispatch customer_login; one of them builds the model from the data
     * object, so the extraction accepts either shape.
     */
    public function testAcceptsTheCustomerDataObjectShapeToo(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(5);

        $this->groupCookie->expects($this->once())->method('write')->with(5);

        $this->observer->execute($this->loginEvent($customer));
    }

    public function testWritesNothingWhenTheSoftPathIsSwitchedOff(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isForceLogoutEnabled')->willReturn(false);

        $this->groupCookie->expects($this->never())->method('write');

        $observer = new RecordGroupOnLogin($config, $this->groupCookie);
        $observer->execute($this->loginEvent($this->createMock(CustomerModel::class)));
    }

    public function testIgnoresAnEventWithoutACustomer(): void
    {
        $this->groupCookie->expects($this->never())->method('write');

        $this->observer->execute(new Observer(['event' => new Event()]));
    }

    /**
     * Leaving the cookie absent hands the decision to the section source, which records the
     * session's group on the next request — the same outcome as a browser that dropped it.
     *
     * @dataProvider missingGroupProvider
     */
    public function testIgnoresACustomerWithoutAGroup(mixed $groupId): void
    {
        $customer = $this->createMock(CustomerModel::class);
        $customer->method('getGroupId')->willReturn($groupId);

        $this->groupCookie->expects($this->never())->method('write');

        $this->observer->execute($this->loginEvent($customer));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function missingGroupProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    private function loginEvent(object $customer): Observer
    {
        return new Observer(['event' => new Event(['customer' => $customer])]);
    }
}
