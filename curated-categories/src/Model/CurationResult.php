<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model;

use Scr1be\CuratedCategories\Api\Data\CurationResultInterface;
use Scr1be\CuratedCategories\Api\Data\CurationTargetInterface;

/**
 * Immutable outcome of one reconcile.
 *
 * The two named constructors exist because a refused run and a completed run carry different
 * information and would otherwise share one constructor full of nulls. `refused()` is not an error
 * object — it is a run the engine decided not to make, which the caller has to be able to tell from
 * a run that made no changes.
 */
class CurationResult implements CurationResultInterface
{
    /**
     * @param int[] $added
     * @param int[] $removed
     * @param int[] $unchanged
     * @param int[] $retainedByFloor
     */
    private function __construct(
        private readonly int $categoryId,
        private readonly string $sourceCode,
        private readonly array $added,
        private readonly array $removed,
        private readonly array $unchanged,
        private readonly array $retainedByFloor,
        private readonly bool $dryRun,
        private readonly ?string $refusalReason
    ) {
    }

    /**
     * @param int[] $added
     * @param int[] $removed
     * @param int[] $unchanged
     * @param int[] $retainedByFloor
     */
    public static function of(
        CurationTargetInterface $target,
        array $added,
        array $removed,
        array $unchanged,
        array $retainedByFloor,
        bool $dryRun
    ): self {
        return new self(
            $target->getCategoryId(),
            $target->getSourceCode(),
            array_values($added),
            array_values($removed),
            array_values($unchanged),
            array_values($retainedByFloor),
            $dryRun,
            null
        );
    }

    public static function refused(CurationTargetInterface $target, string $reason): self
    {
        return new self($target->getCategoryId(), $target->getSourceCode(), [], [], [], [], false, $reason);
    }

    public function getCategoryId(): int
    {
        return $this->categoryId;
    }

    public function getSourceCode(): string
    {
        return $this->sourceCode;
    }

    public function getAdded(): array
    {
        return $this->added;
    }

    public function getRemoved(): array
    {
        return $this->removed;
    }

    public function getUnchanged(): array
    {
        return $this->unchanged;
    }

    public function getRetainedByFloor(): array
    {
        return $this->retainedByFloor;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function isRefused(): bool
    {
        return $this->refusalReason !== null;
    }

    public function getRefusalReason(): ?string
    {
        return $this->refusalReason;
    }
}
