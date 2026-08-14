<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Model\Data;

use Scr1be\PosBridge\Api\Data\CustomerMatchInterface;
use Scr1be\PosBridge\Api\Data\CustomerSearchResultInterface;

class CustomerSearchResult implements CustomerSearchResultInterface
{
    /**
     * @param CustomerMatchInterface[] $items
     * @param bool $hasMore
     */
    public function __construct(
        private readonly array $items,
        private readonly bool $hasMore
    ) {
    }

    /**
     * @return CustomerMatchInterface[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getHasMore(): bool
    {
        return $this->hasMore;
    }
}
