<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\BackInStock\Model\ResourceModel\DeviceTokenWriter;

class DeviceTokenWriterTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private DeviceTokenWriter $writer;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->writer = new DeviceTokenWriter($resource);
    }

    public function testRegisteringTheSameDeviceTwiceIsOneStatement(): void
    {
        $this->connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                DeviceTokenWriter::TABLE,
                [
                    'token_hash' => 'hash',
                    'token' => 'raw',
                    'customer_id' => 7,
                    'website_id' => 1,
                    'platform' => 'web',
                    'is_active' => 1,
                    'deactivated_reason' => null,
                ],
                ['token', 'customer_id', 'website_id', 'platform', 'is_active', 'deactivated_reason']
            );

        $this->writer->upsert('hash', 'raw', 7, 1, 'web');
    }

    public function testTheCustomerIdIsInTheUpdateListSoALogoutUnclaimsTheDevice(): void
    {
        // The row survives, the association does not. Leaving `customer_id` out of the update list
        // would push one person's back-in-stock alerts to whoever is using the machine now.
        $this->connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $data): bool => $data['customer_id'] === null),
                $this->callback(static fn (array $fields): bool => in_array('customer_id', $fields, true))
            );

        $this->writer->upsert('hash', 'raw', null, 1, 'web');
    }

    public function testRegisteringRevivesATokenTheTransportHadRetired(): void
    {
        // The transport's refusal was a snapshot; a registration is current. A browser presenting a
        // token again is a browser that can be reached.
        $this->connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                $this->anything(),
                $this->callback(
                    static fn (array $data): bool => $data['is_active'] === 1 && $data['deactivated_reason'] === null
                ),
                $this->callback(
                    static fn (array $fields): bool => in_array('is_active', $fields, true)
                        && in_array('deactivated_reason', $fields, true)
                )
            );

        $this->writer->upsert('hash', 'raw', 7, 1, 'web');
    }

    public function testDeactivationHashesTheTokensItWasHandedRatherThanMatchingOnThem(): void
    {
        // The transport reports raw tokens because that is what it sent; the unique key is the hash.
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                DeviceTokenWriter::TABLE,
                ['is_active' => 0, 'deactivated_reason' => 'UNREGISTERED'],
                [
                    'token_hash IN (?)' => [hash('sha256', 'alpha'), hash('sha256', 'beta')],
                    'is_active = ?' => 1,
                ]
            )
            ->willReturn(2);

        $this->assertSame(2, $this->writer->deactivate(['alpha', 'beta', 'alpha', ''], 'UNREGISTERED'));
    }

    public function testALongReasonIsTruncatedToTheColumn(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $data): bool => strlen($data['deactivated_reason']) === 64)
            )
            ->willReturn(1);

        $this->writer->deactivate(['alpha'], str_repeat('x', 200));
    }

    public function testNothingToDeactivateIsNotAnUpdateOfEveryRow(): void
    {
        $this->connection->expects($this->never())->method('update');

        $this->assertSame(0, $this->writer->deactivate([], 'UNREGISTERED'));
    }

    public function testAGuestHasNoTokensToRead(): void
    {
        $this->connection->expects($this->never())->method('fetchCol');

        $this->assertSame([], $this->writer->readActiveTokens(0, 1));
    }

    public function testOnlyActiveTokensAreReturned(): void
    {
        $select = $this->createMock(Select::class);
        $clauses = [];

        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            static function (string $condition, $value) use ($select, &$clauses): Select {
                $clauses[$condition] = $value;

                return $select;
            }
        );

        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchCol')->willReturn(['alpha', 'beta']);

        $this->assertSame(['alpha', 'beta'], $this->writer->readActiveTokens(7, 1));
        $this->assertSame(
            ['customer_id = ?' => 7, 'website_id = ?' => 1, 'is_active = ?' => 1],
            $clauses
        );
    }
}
