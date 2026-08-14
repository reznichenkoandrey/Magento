<?php
declare(strict_types=1);

namespace Scr1be\AdminGridToolkit\Test\Unit\Plugin\Grid;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Scr1be\AdminGridToolkit\Model\Config;
use Scr1be\AdminGridToolkit\Model\Grid\CountSelectDejoiner;
use Scr1be\AdminGridToolkit\Plugin\Grid\DejoinGridCount;

class DejoinGridCountTest extends TestCase
{
    private Config&MockObject $config;
    private CountSelectDejoiner&MockObject $dejoiner;
    private SearchResult&MockObject $collection;
    private DejoinGridCount $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->dejoiner = $this->createMock(CountSelectDejoiner::class);
        $this->collection = $this->createMock(SearchResult::class);

        $this->plugin = new DejoinGridCount($this->config, $this->dejoiner);
    }

    public function testTheCountSelectIsHandedOverWithTheCollectionsOwnConnection(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $this->collection->method('getConnection')->willReturn($connection);

        $countSelect = $this->createMock(Select::class);
        $strippedSelect = $this->createMock(Select::class);

        $this->config->method('isGridCountDejoinEnabled')->willReturn(true);
        $this->dejoiner->expects($this->once())
            ->method('stripUnusedJoins')
            ->with($countSelect, $connection)
            ->willReturn($strippedSelect);

        $this->assertSame($strippedSelect, $this->plugin->afterGetSelectCountSql($this->collection, $countSelect));
    }

    public function testTheFixCanBeSwitchedOff(): void
    {
        $countSelect = $this->createMock(Select::class);

        $this->config->method('isGridCountDejoinEnabled')->willReturn(false);
        $this->dejoiner->expects($this->never())->method('stripUnusedJoins');

        $this->assertSame($countSelect, $this->plugin->afterGetSelectCountSql($this->collection, $countSelect));
    }

    /**
     * getSelectCountSql() is untyped in core, and a collection is free to answer with the SQL it
     * assembled itself. There is nothing to rewrite in a string.
     */
    public function testARawSqlStringIsLeftAlone(): void
    {
        $this->config->expects($this->never())->method('isGridCountDejoinEnabled');
        $this->dejoiner->expects($this->never())->method('stripUnusedJoins');

        $sql = 'SELECT COUNT(*) FROM sales_order_grid';

        $this->assertSame($sql, $this->plugin->afterGetSelectCountSql($this->collection, $sql));
    }
}
