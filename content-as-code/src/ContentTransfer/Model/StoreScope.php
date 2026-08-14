<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The one translation every porter needs: store **codes** in the bundle, store **ids** in the
 * database.
 *
 * Store ids are autoincrement values. Two installs that were set up in a different order give the
 * same store view different ids, so an id in a bundle is a coin flip. Codes are typed by a human
 * once and never change, which makes them the only thing worth writing down.
 *
 * Store id 0 (`Store::DEFAULT_STORE_ID`) is the "all store views" row core writes into
 * `cms_page_store` / `cms_block_store`, and it has no code. It is represented in a bundle by the
 * empty store list, which is why an empty list never means "no stores".
 */
class StoreScope
{
    /**
     * Bundle spelling of "assigned to every store view".
     */
    public const ALL_STORES = [];

    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param string[] $codes
     * @return int[]
     * @throws NoSuchEntityException when a code in the bundle does not exist on this install; the
     *         caller turns that into a per-entry failure rather than guessing a store view.
     */
    public function toIds(array $codes): array
    {
        if ($codes === []) {
            return [Store::DEFAULT_STORE_ID];
        }

        $ids = [];

        foreach ($codes as $code) {
            $ids[] = (int)$this->storeManager->getStore($code)->getId();
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param int[]|string[] $ids
     * @return string[] Empty when the entity is assigned to store id 0.
     */
    public function toCodes(array $ids): array
    {
        $codes = [];

        foreach ($ids as $id) {
            $id = (int)$id;

            if ($id === Store::DEFAULT_STORE_ID) {
                return self::ALL_STORES;
            }

            try {
                $codes[] = $this->storeManager->getStore($id)->getCode();
            } catch (NoSuchEntityException) {
                // A store view deleted after the assignment row was written. Dropping it is the
                // only sane reading: the bundle cannot name a store that no longer has a name.
                continue;
            }
        }

        sort($codes);

        return $codes;
    }

    /**
     * Every store view id on this install, admin excluded.
     *
     * @return int[]
     */
    public function allStoreIds(): array
    {
        $ids = [];

        foreach ($this->storeManager->getStores() as $store) {
            $ids[] = (int)$store->getId();
        }

        return $ids;
    }

    /**
     * @return array<int, string> store id => code, admin excluded
     */
    public function storeOptions(): array
    {
        $options = [];

        foreach ($this->storeManager->getStores() as $store) {
            $options[(int)$store->getId()] = (string)$store->getCode();
        }

        return $options;
    }
}
