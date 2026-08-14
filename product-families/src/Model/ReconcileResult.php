<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Model;

use Scr1be\ProductFamilies\Api\Data\ReconcileResultInterface;

/**
 * @see ReconcileResultInterface
 */
final class ReconcileResult implements ReconcileResultInterface
{
    /**
     * @param int[] $affectedProductIds
     */
    private function __construct(
        private readonly string $familyCode,
        private readonly int $linkTypeId,
        private readonly int $familyCount,
        private readonly int $memberCount,
        private readonly int $inserted,
        private readonly int $updated,
        private readonly int $deleted,
        private readonly int $unchanged,
        private readonly array $affectedProductIds,
        private readonly bool $dryRun,
        private readonly ?string $refusalReason
    ) {
    }

    public static function fromPlan(
        string $familyCode,
        int $linkTypeId,
        int $familyCount,
        int $memberCount,
        LinkPlan $plan,
        bool $dryRun
    ): self {
        return new self(
            $familyCode,
            $linkTypeId,
            $familyCount,
            $memberCount,
            count($plan->getInserts()),
            count($plan->getUpdates()),
            count($plan->getDeletes()),
            $plan->getUnchangedCount(),
            $plan->getAffectedProductIds(),
            $dryRun,
            null
        );
    }

    public static function refused(string $familyCode, int $linkTypeId, string $reason): self
    {
        return new self($familyCode, $linkTypeId, 0, 0, 0, 0, 0, 0, [], false, $reason);
    }

    public function getFamilyCode(): string
    {
        return $this->familyCode;
    }

    public function getLinkTypeId(): int
    {
        return $this->linkTypeId;
    }

    public function getFamilyCount(): int
    {
        return $this->familyCount;
    }

    public function getMemberCount(): int
    {
        return $this->memberCount;
    }

    public function getInsertedCount(): int
    {
        return $this->inserted;
    }

    public function getUpdatedCount(): int
    {
        return $this->updated;
    }

    public function getDeletedCount(): int
    {
        return $this->deleted;
    }

    public function getUnchangedCount(): int
    {
        return $this->unchanged;
    }

    public function getAffectedProductIds(): array
    {
        return $this->affectedProductIds;
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
