<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ResourceModel\Slider\Grid;

use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\View\Element\UiComponent\DataProvider\Document;
use Psr\Log\LoggerInterface;
use Scr1be\HyvaProductSlider\Model\ResourceModel\Slider\Collection as SliderCollection;

/**
 * The listing's data source.
 *
 * `Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult` would have covered a plain
 * table, but the grid has to filter and display store views, which live in the pivot — so the grid
 * collection extends the entity collection instead and inherits its join, its `_afterLoad()` and its
 * count fix. The `SearchResultInterface` half below is the adapter the UI data provider expects;
 * paging and sorting are applied by core, so those methods are intentionally inert.
 */
class Collection extends SliderCollection implements SearchResultInterface
{
    private AggregationInterface $aggregations;

    /**
     * @param string $mainTable
     * @param string $eventPrefix
     * @param string $eventObject
     * @param string $resourceModel
     * @param string $model
     * @param AdapterInterface|string|null $connection
     */
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        $mainTable,
        $eventPrefix,
        $eventObject,
        $resourceModel,
        $model = Document::class,
        $connection = null,
        ?AbstractDb $resource = null
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $connection, $resource);
        $this->_eventPrefix = $eventPrefix;
        $this->_eventObject = $eventObject;
        $this->_init($model, $resourceModel);
        $this->setMainTable($mainTable);
    }

    public function getAggregations(): AggregationInterface
    {
        return $this->aggregations;
    }

    /**
     * @param AggregationInterface $aggregations
     * @return $this
     */
    public function setAggregations($aggregations): self
    {
        $this->aggregations = $aggregations;

        return $this;
    }

    public function getSearchCriteria(): ?SearchCriteriaInterface
    {
        return null;
    }

    /**
     * @return $this
     */
    public function setSearchCriteria(?SearchCriteriaInterface $searchCriteria = null): self
    {
        return $this;
    }

    public function getTotalCount(): int
    {
        return $this->getSize();
    }

    /**
     * @param int $totalCount
     * @return $this
     */
    public function setTotalCount($totalCount): self
    {
        return $this;
    }

    /**
     * @param \Magento\Framework\Api\ExtensibleDataInterface[]|null $items
     * @return $this
     */
    public function setItems(?array $items = null): self
    {
        return $this;
    }
}
