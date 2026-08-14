<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\ProductFamilies\Model\ResourceModel\LinkPositionAttribute;

class LinkPositionAttributeTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private LinkPositionAttribute $attribute;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->attribute = new LinkPositionAttribute($resource);
    }

    /**
     * The id is read on every link read and every link write. Once per request is the difference
     * between one query on a product page and four.
     */
    public function testTheLookupHappensOncePerLinkType(): void
    {
        $this->connection->expects($this->once())->method('fetchOne')->willReturn('7');

        $this->assertSame(7, $this->attribute->getId(21));
        $this->assertSame(7, $this->attribute->getId(21));
    }

    public function testEachLinkTypeIsLookedUpSeparately(): void
    {
        $this->connection->expects($this->exactly(2))->method('fetchOne')->willReturn('7');

        $this->attribute->getId(21);
        $this->attribute->getId(22);
    }

    /**
     * A link type whose attribute row is missing cannot store positions, and silently writing links
     * without them would produce rows that render in insertion order — a plausible-looking wrong
     * answer rather than a visible failure.
     */
    public function testAMissingAttributeRowIsAnErrorRatherThanAZero(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('setup:upgrade');

        $this->attribute->getId(21);
    }
}
