<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model\Content;

use Magento\Cms\Model\ResourceModel\Block\CollectionFactory;

/**
 * `block_id` → `identifier` for every CMS block on this install, loaded once.
 *
 * One query for the whole table beats one query per reference: a homepage bundle can carry a dozen
 * blocks each embedding two more, and the map is read once per reference found. Only the two columns
 * that matter are selected — a CMS block's `content` is the largest column in the table and none of
 * it is wanted here.
 */
class BlockIdentifierMap
{
    /**
     * @var array<int, string>|null
     */
    private ?array $map = null;

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function identifierFor(int $blockId): ?string
    {
        return $this->all()[$blockId] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToSelect(['block_id', 'identifier']);

        $map = [];

        foreach ($collection as $block) {
            $map[(int)$block->getId()] = (string)$block->getIdentifier();
        }

        return $this->map = $map;
    }
}
