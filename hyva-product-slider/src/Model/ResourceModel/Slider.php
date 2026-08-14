<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Store\Model\Store;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;

/**
 * The slider row plus its store pivot.
 *
 * Store scope is a second table rather than a column because a slider is genuinely many-to-many with
 * store views — the same "New In" carousel usually runs on three of five stores — and because
 * `store_id = 0` then keeps its ordinary Magento meaning of "all of them" instead of being a fourth
 * kind of value the reader has to special-case.
 *
 * Unlike CMS blocks, a slider identifier is unique across the whole install (a `unique` constraint in
 * `db_schema.xml`, not a `_beforeSave()` check). Two sliders called `homepage-new` on different
 * stores would make `getByIdentifier()` a store-sensitive lookup at every call site, including layout
 * XML, where the store is not in the argument list.
 */
class Slider extends AbstractDb
{
    public const MAIN_TABLE = 'scr1be_slider';
    public const STORE_TABLE = 'scr1be_slider_store';

    protected function _construct(): void
    {
        $this->_init(self::MAIN_TABLE, SliderInterface::SLIDER_ID);
    }

    /**
     * Rewrites the pivot only when the caller actually supplied stores.
     *
     * A missing `store_id` means "this save did not touch scope" — an inline grid edit of the title,
     * for instance — and deleting every row for a key the caller never mentioned is how a slider
     * disappears from the storefront after an unrelated edit.
     */
    protected function _afterSave(AbstractModel $object): self
    {
        $storeIds = $object->getData(SliderInterface::STORE_ID);

        if ($storeIds === null) {
            return $this;
        }

        $sliderId = (int) $object->getId();
        $connection = $this->getConnection();
        $table = $this->getTable(self::STORE_TABLE);

        $connection->delete($table, ['slider_id = ?' => $sliderId]);

        $rows = [];
        foreach (array_unique(array_map('intval', (array) $storeIds)) as $storeId) {
            $rows[] = ['slider_id' => $sliderId, 'store_id' => $storeId];
        }

        if ($rows !== []) {
            $connection->insertMultiple($table, $rows);
        }

        return $this;
    }

    protected function _afterLoad(AbstractModel $object): self
    {
        if ($object->getId()) {
            $object->setData(SliderInterface::STORE_ID, $this->lookupStoreIds((int) $object->getId()));
        }

        return $this;
    }

    /**
     * @return int[]
     */
    public function lookupStoreIds(int $sliderId): array
    {
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from($this->getTable(self::STORE_TABLE), 'store_id')
            ->where('slider_id = ?', $sliderId);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Resolve an identifier to an id, honouring store scope.
     *
     * `Store::DEFAULT_STORE_ID` is always accepted alongside the requested store because that is what
     * the admin form writes for "All Store Views"; a slider assigned to it is assigned everywhere.
     */
    public function getSliderIdByIdentifier(string $identifier, ?int $storeId = null): ?int
    {
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from(['s' => $this->getMainTable()], [SliderInterface::SLIDER_ID])
            ->where('s.identifier = ?', $identifier)
            ->limit(1);

        if ($storeId !== null) {
            $select->join(
                ['ss' => $this->getTable(self::STORE_TABLE)],
                's.slider_id = ss.slider_id',
                []
            )->where('ss.store_id IN (?)', [Store::DEFAULT_STORE_ID, $storeId]);
        }

        $sliderId = $connection->fetchOne($select);

        return $sliderId === false || $sliderId === null || $sliderId === '' ? null : (int) $sliderId;
    }
}
