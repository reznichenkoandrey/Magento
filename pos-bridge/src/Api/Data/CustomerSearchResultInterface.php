<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Api\Data;

/**
 * A capped match list, and an honest flag saying whether the cap was hit.
 *
 * There is no page number and no total count on purpose. A queue at a till is not paginated: the
 * operator either sees the right person or types another word. `has_more` is the signal that typing
 * another word is worth it, and it costs one extra row rather than a second COUNT query over the
 * same joins.
 *
 * @api
 */
interface CustomerSearchResultInterface
{
    /**
     * @return \Scr1be\PosBridge\Api\Data\CustomerMatchInterface[]
     */
    public function getItems(): array;

    /**
     * True when the search stopped at the configured cap and the shopper may be further down.
     *
     * @return bool
     */
    public function getHasMore(): bool;
}
