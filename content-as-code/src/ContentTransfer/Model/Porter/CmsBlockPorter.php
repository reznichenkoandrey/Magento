<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model\Porter;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Model\Block;
use Magento\Cms\Model\BlockFactory;
use Magento\Cms\Model\ResourceModel\Block\Collection;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Scr1be\ContentTransfer\Api\Data\EntryInterface;
use Scr1be\ContentTransfer\Api\PorterInterface;
use Scr1be\ContentTransfer\Model\Content\ContentTransformer;
use Scr1be\ContentTransfer\Model\Entry;
use Scr1be\ContentTransfer\Model\ImportMode;
use Scr1be\ContentTransfer\Model\Outcome;
use Scr1be\ContentTransfer\Model\Selection;
use Scr1be\ContentTransfer\Model\StoreScope;
use Scr1be\ContentTransfer\Model\Summary;

/**
 * CMS blocks.
 *
 * First in the dependency order and depended on by everything else, because a block is what pages
 * and widgets point *at*: a page carrying `{{widget type="Magento\Cms\Block\Widget\Block"
 * block_id="footer-links"}}` needs `footer-links` to exist before it is worth importing.
 *
 * Four columns are deliberately absent from the payload. `block_id` is an autoincrement and the
 * whole reason this module exists. `creation_time` and `update_time` are `CURRENT_TIMESTAMP`
 * columns in `Magento_Cms/etc/db_schema.xml` — capturing them would put a changing value in a file
 * whose value is that it stops changing.
 */
class CmsBlockPorter implements PorterInterface
{
    public const CODE = 'cms_block';

    public const KEY_IDENTIFIER = 'identifier';
    public const KEY_TITLE = 'title';
    public const KEY_CONTENT = 'content';
    public const KEY_IS_ACTIVE = 'is_active';
    public const KEY_STORES = 'stores';

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly BlockFactory $blockFactory,
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly ContentTransformer $contentTransformer,
        private readonly StoreScope $storeScope,
        private readonly StoreScopedKey $key
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return (string)__('CMS Blocks');
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function summarize(Selection $selection): array
    {
        $summaries = [];

        foreach ($this->collection($selection) as $block) {
            $summaries[] = new Summary(
                $this->keyFor($block),
                (string)$block->getTitle(),
                $this->storeCodesOf($block)
            );
        }

        return $summaries;
    }

    /**
     * The bundle key for a loaded block. Public because the mass action on the native CMS block grid
     * has to turn selected rows into keys, and deriving that in a controller is how the two drift.
     */
    public function keyFor(Block $block): string
    {
        return $this->key->build((string)$block->getIdentifier(), $this->storeCodesOf($block));
    }

    public function capture(Selection $selection): array
    {
        $entries = [];

        foreach ($this->collection($selection) as $block) {
            $storeCodes = $this->storeCodesOf($block);
            $key = $this->keyFor($block);

            if (!$selection->includesIdentifier(self::CODE, $key)) {
                continue;
            }

            $rewrite = $this->contentTransformer->toPortable(
                $block->getContent(),
                self::CODE . '/' . $key
            );

            $entries[] = new Entry(
                self::CODE,
                $key,
                [
                    self::KEY_IDENTIFIER => (string)$block->getIdentifier(),
                    self::KEY_TITLE => (string)$block->getTitle(),
                    self::KEY_CONTENT => $rewrite->getContent(),
                    self::KEY_IS_ACTIVE => (bool)$block->isActive(),
                    self::KEY_STORES => $storeCodes,
                ],
                $rewrite->getTransforms(),
                $rewrite->getWarnings()
            );
        }

        return $entries;
    }

    public function exists(EntryInterface $entry): bool
    {
        return $this->find($entry) !== null;
    }

    public function apply(EntryInterface $entry, ImportMode $mode): Outcome
    {
        $payload = $entry->getPayload();
        $identifier = (string)($payload[self::KEY_IDENTIFIER] ?? '');

        if ($identifier === '') {
            throw new LocalizedException(new Phrase('The entry has no block identifier.'));
        }

        $existing = $this->find($entry);

        if ($existing !== null && !$mode->replacesExisting()) {
            return Outcome::skipped((string)__('Block "%1" is already here.', $identifier));
        }

        $block = $existing ?? $this->blockFactory->create();
        $storeIds = $this->storeScope->toIds((array)($payload[self::KEY_STORES] ?? []));

        $block->setIdentifier($identifier);
        $block->setTitle((string)($payload[self::KEY_TITLE] ?? $identifier));
        $block->setContent((string)($payload[self::KEY_CONTENT] ?? ''));
        $block->setIsActive((bool)($payload[self::KEY_IS_ACTIVE] ?? true));

        // `stores` and not only `store_id`: the block's store relation save handler
        // (Magento\Cms\Model\ResourceModel\Block\Relation\Store\SaveHandler) reads `getStores()`
        // and nothing else — unlike the page one, which falls back to `getStoreId()`. Setting
        // `store_id` as well keeps BlockRepository::save() from stamping the current store onto the
        // model when it finds that field empty.
        $block->setData(self::KEY_STORES, $storeIds);
        $block->setData('store_id', $storeIds);

        $this->blockRepository->save($block);

        return $existing !== null
            ? Outcome::replaced((string)__('Block "%1" was overwritten.', $identifier))
            : Outcome::created((string)__('Block "%1" was created.', $identifier));
    }

    private function collection(Selection $selection): Collection
    {
        $collection = $this->collectionFactory->create();

        if ($selection->hasStoreFilter()) {
            // `$withAdmin = true` adds store id 0, so blocks assigned to All Store Views come along
            // with a store-scoped capture — which is what an operator asking for "the German store"
            // means, since those blocks render there too.
            $collection->addStoreFilter($selection->getStoreIds());
        }

        return $collection;
    }

    /**
     * A CMS identifier is unique per store scope, not globally, so both halves of the key have to
     * match. The store scope is compared as a set: order in `cms_block_store` is not meaningful.
     */
    private function find(EntryInterface $entry): ?Block
    {
        $payload = $entry->getPayload();
        $identifier = (string)($payload[self::KEY_IDENTIFIER] ?? '');
        $wantedStores = (array)($payload[self::KEY_STORES] ?? []);
        sort($wantedStores);

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(self::KEY_IDENTIFIER, $identifier);

        foreach ($collection as $block) {
            if ($this->storeCodesOf($block) === $wantedStores) {
                return $block;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function storeCodesOf(Block $block): array
    {
        // The collection's `_afterLoad()` calls `performAfterLoad('cms_block_store', …)`, which sets
        // `store_id` to the **array** of assigned store ids. A single loaded model gets the same
        // treatment from its read handler, so this works for both.
        return $this->storeScope->toCodes((array)$block->getData('store_id'));
    }
}
