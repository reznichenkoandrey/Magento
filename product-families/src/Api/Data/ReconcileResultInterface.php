<?php
declare(strict_types=1);

namespace Scr1be\ProductFamilies\Api\Data;

/**
 * What one family's reconcile did, or would have done.
 *
 * A refused run is not a failure: a family that is switched off or half-configured has a reason, the
 * reason is worth printing, and the caller's exit code should not change because of it. That is why
 * refusal is a state on the result rather than an exception.
 *
 * @api
 */
interface ReconcileResultInterface
{
    public function getFamilyCode(): string;

    public function getLinkTypeId(): int;

    /**
     * Families of two or more members found by the scan — the size of the problem, before any cap.
     */
    public function getFamilyCount(): int;

    /**
     * Family memberships, not distinct products: a multiselect family key puts one product in
     * several families, and each of those is work the run did.
     */
    public function getMemberCount(): int;

    public function getInsertedCount(): int;

    public function getUpdatedCount(): int;

    public function getDeletedCount(): int;

    public function getUnchangedCount(): int;

    /**
     * @return int[] products whose family row changed, and therefore whose page cache needs evicting
     */
    public function getAffectedProductIds(): array;

    public function isDryRun(): bool;

    public function isRefused(): bool;

    public function getRefusalReason(): ?string;
}
