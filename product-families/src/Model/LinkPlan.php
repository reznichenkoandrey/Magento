<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model;

/**
 * The delta between what the pipeline computed and what is in `catalog_product_link` — the only
 * thing the writer is ever given.
 *
 * Keeping the plan as a value makes the dry run free: the CLI prints the same object the writer
 * would have executed, so what `--dry-run` reports is not a second implementation's opinion of what
 * would happen.
 */
final class LinkPlan
{
    /**
     * @param array<int, array{product_id: int, linked_product_id: int, position: int}> $inserts
     * @param array<int, array{link_id: int, position: int}> $updates
     * @param int[] $deletes link ids
     * @param int[] $affectedProductIds products whose rendered row changes as a result
     * @param int $unchanged links that were already correct, for the report
     */
    public function __construct(
        private readonly array $inserts,
        private readonly array $updates,
        private readonly array $deletes,
        private readonly array $affectedProductIds,
        private readonly int $unchanged
    ) {
    }

    /**
     * @return array<int, array{product_id: int, linked_product_id: int, position: int}>
     */
    public function getInserts(): array
    {
        return $this->inserts;
    }

    /**
     * @return array<int, array{link_id: int, position: int}>
     */
    public function getUpdates(): array
    {
        return $this->updates;
    }

    /**
     * @return int[]
     */
    public function getDeletes(): array
    {
        return $this->deletes;
    }

    /**
     * @return int[]
     */
    public function getAffectedProductIds(): array
    {
        return $this->affectedProductIds;
    }

    public function getUnchangedCount(): int
    {
        return $this->unchanged;
    }

    public function isEmpty(): bool
    {
        return $this->inserts === [] && $this->updates === [] && $this->deletes === [];
    }
}
