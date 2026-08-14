<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Model\ResourceModel\FamilyLinkReader;
use Scr1be\ProductFamilies\Model\ResourceModel\LinkPositionAttribute;

class FamilyLinkReaderTest extends TestCase
{
    private const LINK_TYPE_ID = 22;
    private const POSITION_ATTRIBUTE_ID = 7;

    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private FamilyLinkReader $reader;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->select = $this->createMock(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->select->method('joinLeft')->willReturnSelf();
        $this->select->method('where')->willReturnSelf();
        $this->select->method('order')->willReturnSelf();
        $this->connection->method('select')->willReturn($this->select);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $positionAttribute = $this->createMock(LinkPositionAttribute::class);
        $positionAttribute->method('getId')->willReturn(self::POSITION_ATTRIBUTE_ID);

        $this->reader = new FamilyLinkReader($resource, $positionAttribute);
    }

    /**
     * The tie-break is the point. Two links sharing a position — which a multiselect family key
     * produces — would otherwise come back in whatever order InnoDB felt like, and the row would
     * appear to shuffle between page loads.
     */
    public function testOrdersByPositionThenLinkId(): void
    {
        $this->select->expects($this->once())
            ->method('order')
            ->with(['p.value ASC', 'l.link_id ASC']);
        $this->connection->method('fetchCol')->willReturn([]);

        $this->reader->getLinkedProductIds(5, self::LINK_TYPE_ID);
    }

    public function testReturnsIntegersRatherThanTheStringsTheDriverHandsBack(): void
    {
        $this->connection->method('fetchCol')->willReturn(['12', '7']);

        $this->assertSame([12, 7], $this->reader->getLinkedProductIds(5, self::LINK_TYPE_ID));
    }

    /**
     * A product page rendered for an unsaved or missing product should not reach the database at
     * all — the reader is called once per family on every product view.
     */
    public function testAProductWithoutAnIdIsAnsweredWithoutAQuery(): void
    {
        $this->connection->expects($this->never())->method('select');

        $this->assertSame([], $this->reader->getLinkedProductIds(0, self::LINK_TYPE_ID));
    }
}
