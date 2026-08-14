<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model;

use Scr1be\ContentTransfer\Api\Data\EntryInterface;

/**
 * The result of one `apply` run, entry by entry.
 *
 * Kept as a list rather than a running tally because the useful output of an import is "these three
 * failed and here is why", and a tally cannot be turned back into that.
 */
class ImportReport
{
    /**
     * @var array<int, array{porter: string, identifier: string, outcome: Outcome}>
     */
    private array $rows = [];

    public function record(EntryInterface $entry, Outcome $outcome): void
    {
        $this->rows[] = [
            'porter' => $entry->getPorterCode(),
            'identifier' => $entry->getIdentifier(),
            'outcome' => $outcome,
        ];
    }

    /**
     * @return array<int, array{porter: string, identifier: string, outcome: Outcome}>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * @return array<int, array{porter: string, identifier: string, outcome: Outcome}>
     */
    public function getFailures(): array
    {
        return array_values(
            array_filter($this->rows, static fn (array $row): bool => $row['outcome']->isFailure())
        );
    }

    public function hasFailures(): bool
    {
        return $this->getFailures() !== [];
    }

    public function hasWrites(): bool
    {
        foreach ($this->rows as $row) {
            if ($row['outcome']->isWrite()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int> status => count, in the fixed order the console prints them.
     */
    public function getTotals(): array
    {
        $totals = [
            Outcome::STATUS_CREATED => 0,
            Outcome::STATUS_REPLACED => 0,
            Outcome::STATUS_SKIPPED => 0,
            Outcome::STATUS_FAILED => 0,
        ];

        foreach ($this->rows as $row) {
            $status = $row['outcome']->getStatus();
            $totals[$status] = ($totals[$status] ?? 0) + 1;
        }

        return $totals;
    }
}
