<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Test\Unit\Model\ResourceModel;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\CategoryCascade\Model\ResourceModel\OverrideSweeper;

class OverrideSweeperTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private OverrideSweeper $sweeper;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')
            ->willReturnCallback(static fn (string $table): string => 'prefix_' . $table);

        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getAttributeId')->willReturn('42');
        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturn($attribute);

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $metadataPool = $this->createMock(MetadataPool::class);
        $metadataPool->method('getMetadata')
            ->with(CategoryInterface::class)
            ->willReturn($metadata);

        $this->sweeper = new OverrideSweeper($resourceConnection, $eavConfig, $metadataPool);
    }

    /**
     * One statement, and every part of the WHERE matters: the attribute so nothing else is
     * touched, the store scope so the default row this cascade just wrote is left alone, and
     * value = 1 so a merchant's existing per-store "off" is not counted as a change.
     */
    public function testTurnsEveryEnablingStoreOverrideOffInOneStatement(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                'prefix_catalog_category_entity_int',
                ['value' => 0],
                [
                    'attribute_id = ?' => 42,
                    'store_id > ?' => 0,
                    'value = ?' => 1,
                    'entity_id IN (?)' => [31, 32],
                ]
            )
            ->willReturn(3);

        $this->assertSame(3, $this->sweeper->clearEnabledOverrides([31, 32]));
    }

    public function testDoesNotQueryForAnEmptySubtree(): void
    {
        $this->connection->expects($this->never())->method('update');

        $this->assertSame(0, $this->sweeper->clearEnabledOverrides([]));
    }
}
