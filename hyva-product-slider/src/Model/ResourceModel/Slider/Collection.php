<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ResourceModel\Slider;

use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Store\Model\Store;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Model\ResourceModel\Slider as SliderResource;
use Scr1be\HyvaProductSlider\Model\Slider;

class Collection extends AbstractCollection
{
    protected $_idFieldName = SliderInterface::SLIDER_ID;

    protected $_eventPrefix = 'scr1be_slider_collection';

    protected $_eventObject = 'slider_collection';

    protected function _construct(): void
    {
        $this->_init(Slider::class, SliderResource::class);
        $this->_map['fields']['store'] = 'store_table.store_id';
        $this->_map['fields']['slider_id'] = 'main_table.slider_id';
    }

    /**
     * Loads every row's store ids in one query rather than one per slider.
     *
     * The grid renders a store column for a page of sliders at a time, so the N+1 this avoids is
     * small but permanent — and the same collection serves the storefront, where a page can
     * legitimately hold several sliders.
     */
    protected function _afterLoad(): self
    {
        $sliderIds = array_map('intval', $this->getColumnValues(SliderInterface::SLIDER_ID));

        if ($sliderIds === []) {
            return parent::_afterLoad();
        }

        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable(SliderResource::STORE_TABLE), ['slider_id', 'store_id'])
            ->where('slider_id IN (?)', $sliderIds);

        $storesBySlider = [];
        foreach ($connection->fetchAll($select) as $row) {
            $storesBySlider[(int) $row['slider_id']][] = (int) $row['store_id'];
        }

        foreach ($this as $slider) {
            $slider->setData(SliderInterface::STORE_ID, $storesBySlider[(int) $slider->getId()] ?? []);
        }

        return parent::_afterLoad();
    }

    /**
     * The grid's store filter arrives as an ordinary field, but `store_id` is not a column on the
     * main table — it lives in the pivot — so it is rerouted rather than passed down to a `WHERE`
     * that would fail on an unknown column. Core's own CMS collections intercept it in the same
     * place, for the same reason.
     *
     * `$withAdmin = false` here: filtering the grid to one store should list the sliders assigned to
     * that store, not those plus every all-stores slider.
     *
     * @param array|string $field
     * @param string|int|array|null $condition
     * @return $this
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if ($field === SliderInterface::STORE_ID) {
            return $this->addStoreFilter($condition, false);
        }

        return parent::addFieldToFilter($field, $condition);
    }

    /**
     * The condition is flattened rather than cast, because it arrives in three different shapes: a
     * bare id from the storefront, a list from a caller that knows several, and a condition map like
     * `['eq' => 3]` or `['in' => [1, 2]]` from the grid — `UiComponent\DataProvider\RegularFilter`
     * builds that last one before calling `addFieldToFilter()`.
     *
     * @param int|int[]|array<string, int|int[]>|Store $store
     * @return $this
     */
    public function addStoreFilter($store, bool $withAdmin = true): self
    {
        if ($store instanceof Store) {
            $store = [(int) $store->getId()];
        }

        $storeIds = [];
        foreach ((array) $store as $condition) {
            foreach ((array) $condition as $storeId) {
                $storeIds[] = (int) $storeId;
            }
        }

        if ($withAdmin) {
            $storeIds[] = Store::DEFAULT_STORE_ID;
        }

        $this->addFilter('store', ['in' => array_values(array_unique($storeIds))], 'public');

        return $this;
    }

    public function addActiveFilter(): self
    {
        $this->addFieldToFilter(SliderInterface::IS_ACTIVE, 1);

        return $this;
    }

    /**
     * Joins the pivot only when something actually filtered on it.
     *
     * A collection nobody scopes stays a single-table scan, and — more importantly — the join is
     * added exactly once, from the one place that runs immediately before filters are rendered. A
     * repeated join is a silently multiplied row count that surfaces only as a wrong `getSize()`.
     */
    protected function _renderFiltersBefore(): void
    {
        if ($this->getFilter('store')) {
            $this->getSelect()->join(
                ['store_table' => $this->getTable(SliderResource::STORE_TABLE)],
                'main_table.slider_id = store_table.slider_id',
                []
            )->group('main_table.slider_id');
        }

        parent::_renderFiltersBefore();
    }

    /**
     * The grouped select above would otherwise make `COUNT(*)` return the size of the first group —
     * always 1 — instead of the number of sliders. Core's own CMS collections strip the GROUP for
     * exactly this reason.
     */
    public function getSelectCountSql(): Select
    {
        $countSelect = parent::getSelectCountSql();
        $countSelect->reset(Select::GROUP);

        return $countSelect;
    }
}
