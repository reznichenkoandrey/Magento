<?php
declare(strict_types=1);

namespace Scr1be\CategoryCascade\Model;

/**
 * What one cascade actually did.
 *
 * The three numbers are kept apart because they answer different questions: the subtree is what
 * has to be cache-invalidated, the disabled ids are what changed, and the cleared overrides are
 * the per-store rows that would otherwise have kept a child visible in one store view while its
 * parent was gone. A cascade that disabled nothing but cleared overrides is still a cascade.
 */
class CascadeResult
{
    /**
     * @param int[] $subtreeIds every descendant the walk saw, in level order
     * @param int[] $disabledIds the subset that was enabled in the target scope and is now off
     */
    public function __construct(
        private readonly array $subtreeIds,
        private readonly array $disabledIds,
        private readonly int $clearedOverrideRows
    ) {
    }

    /**
     * @return int[]
     */
    public function getSubtreeIds(): array
    {
        return $this->subtreeIds;
    }

    /**
     * @return int[]
     */
    public function getDisabledIds(): array
    {
        return $this->disabledIds;
    }

    public function getClearedOverrideRows(): int
    {
        return $this->clearedOverrideRows;
    }

    public function hasChanges(): bool
    {
        return $this->disabledIds !== [] || $this->clearedOverrideRows > 0;
    }
}
