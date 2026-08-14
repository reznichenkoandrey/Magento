<?php
declare(strict_types=1);

namespace Scr1be\AdminGridToolkit\Model\Grid;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Psr\Log\LoggerInterface;

/**
 * Removes allowlisted LEFT JOINs from a grid's COUNT(*) select.
 *
 * Magento\Framework\Data\Collection\AbstractDb::getSelectCountSql() builds the pager's total by
 * cloning the grid's select and resetting ORDER, LIMIT, COLUMNS and GROUP. It never resets FROM.
 * Every join a module added to render a column therefore runs again for a query whose only output
 * is a number, on the full filtered row set rather than the twenty rows of the current page — and
 * unlike MariaDB, whose optimiser eliminates provably redundant LEFT JOINs, MySQL 8 executes them.
 *
 * Core does ship a routine for this shape of surgery, Magento\Framework\DB\Select::resetJoinLeft(),
 * and nothing calls it on a count select. It is also not the right tool here: it removes *every*
 * LEFT JOIN it finds unused, and a join with more than one matching row per main row multiplies
 * COUNT(*). Dropping such a join does not make the count faster, it makes it different — and it is
 * the pager, so nobody notices until someone reconciles a report. That is why this class works from
 * an explicit list instead of a heuristic: naming a correlation name in di.xml is a reviewable
 * assertion that the join matches at most one row, which is the one fact no amount of SQL
 * inspection can establish.
 *
 * Everything else is inspected rather than assumed. A join stays if its alias or table name appears
 * in the count's WHERE, HAVING or column expressions, if one of the joined table's own columns
 * appears unqualified there, if another surviving join references it, or if any part of that
 * analysis fails. The select is only rewritten when all of those questions have been answered.
 */
class CountSelectDejoiner
{
    /**
     * Matches an SQL identifier that is not part of a longer one, with or without backquotes.
     */
    private const IDENTIFIER_PATTERN = '/(?<![A-Za-z0-9_$])`?%s`?(?![A-Za-z0-9_$])/i';

    /**
     * Matches a qualified column reference — `alias`.`column`, alias.column, and the spacing
     * variants in between.
     */
    private const QUALIFIED_REFERENCE_PATTERN = '/`?[A-Za-z0-9_$]+`?\s*\.\s*`?[A-Za-z0-9_$]+`?/';

    /**
     * @param LoggerInterface $logger
     * @param string[] $removableJoins Correlation names allowed to leave the count select
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $removableJoins = []
    ) {
    }

    /**
     * The count select is a clone core made for this one query and handed straight back, so it is
     * rewritten in place rather than cloned again. Any failure in the analysis returns it exactly
     * as core built it — the FROM part is only written once every candidate has been cleared.
     */
    public function stripUnusedJoins(Select $countSelect, AdapterInterface $connection): Select
    {
        if ($this->removableJoins === []) {
            return $countSelect;
        }

        try {
            return $this->removeClearedJoins($countSelect, $connection);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Scr1be_AdminGridToolkit: count select left untouched, the join analysis failed.',
                ['exception' => $e]
            );

            return $countSelect;
        }
    }

    private function removeClearedJoins(Select $countSelect, AdapterInterface $connection): Select
    {
        $from = $countSelect->getPart(Select::FROM);

        // A UNION assembles selects this one does not own; the FROM in hand is not the whole query.
        if (!is_array($from) || $from === [] || $countSelect->getPart(Select::UNION)) {
            return $countSelect;
        }

        $filterSql = $this->collectFilterSql($countSelect);

        $removable = [];
        foreach ($from as $correlationName => $joinSpec) {
            $correlationName = (string) $correlationName;
            if (!$this->isCandidate($correlationName, $joinSpec)) {
                continue;
            }
            if ($this->isNeededByFilters($correlationName, $joinSpec, $filterSql, $connection)) {
                continue;
            }
            $removable[] = $correlationName;
        }

        $removable = $this->keepJoinsOtherJoinsDependOn($from, $removable);
        if ($removable === []) {
            return $countSelect;
        }

        $countSelect->setPart(Select::FROM, array_diff_key($from, array_flip($removable)));

        return $countSelect;
    }

    /**
     * The first FROM entry is the main table and carries no join type; INNER, CROSS and RIGHT joins
     * decide which rows exist at all, so only a LEFT JOIN can be a candidate.
     *
     * @param array<string, mixed> $joinSpec
     */
    private function isCandidate(string $correlationName, array $joinSpec): bool
    {
        return ($joinSpec['joinType'] ?? null) === Select::LEFT_JOIN
            && in_array($correlationName, $this->removableJoins, true);
    }

    /**
     * Two questions, and either one keeps the join.
     *
     * The first is whether the alias or the table name is named anywhere in the count's conditions
     * or its remaining column expression — the latter matters because a grid with a GROUP BY has
     * its grouping columns folded into COUNT(DISTINCT ...) by core before this class sees them.
     *
     * The second is the one the alias check cannot answer. A grid filter is rendered by
     * AbstractDb::addFieldToFilter() as a quoted identifier with no table qualifier, so a filter on
     * a joined column reads `status` = 'x' and names nothing this class could match. Comparing
     * against the joined table's own column list closes that gap, and it is exact rather than
     * merely cautious: an unqualified column that existed in both the main table and the joined
     * table would make the grid's own query ambiguous and MySQL would refuse to run it. Because the
     * grid does run, an unqualified name found in the joined table's columns cannot belong to the
     * main table — it is that join's column, and the join has to stay.
     *
     * @param array<string, mixed> $joinSpec
     */
    private function isNeededByFilters(
        string $correlationName,
        array $joinSpec,
        string $filterSql,
        AdapterInterface $connection
    ): bool {
        foreach ($this->identifiersFor($correlationName, $joinSpec) as $identifier) {
            if ($this->referencesIdentifier($identifier, $filterSql)) {
                return true;
            }
        }

        $columns = $this->joinedTableColumns($joinSpec, $connection);
        if ($columns === null) {
            return true;
        }

        $unqualified = $this->stripQualifiedReferences($filterSql);
        foreach ($columns as $column) {
            if ($this->referencesIdentifier($column, $unqualified)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A join whose condition another surviving join depends on cannot leave, and removing one join
     * can promote the next into that position — so this runs to a fixed point rather than once.
     *
     * @param array<string, array<string, mixed>> $from
     * @param string[] $removable
     * @return string[]
     */
    private function keepJoinsOtherJoinsDependOn(array $from, array $removable): array
    {
        do {
            $rescued = [];
            foreach ($removable as $candidate) {
                foreach ($from as $correlationName => $joinSpec) {
                    $correlationName = (string) $correlationName;
                    if ($correlationName === $candidate || in_array($correlationName, $removable, true)) {
                        continue;
                    }
                    $condition = $this->toSql($joinSpec['joinCondition'] ?? '');
                    if ($condition === '') {
                        continue;
                    }
                    foreach ($this->identifiersFor($candidate, $from[$candidate]) as $identifier) {
                        if ($this->referencesIdentifier($identifier, $condition)) {
                            $rescued[] = $candidate;
                            continue 3;
                        }
                    }
                }
            }
            $removable = array_values(array_diff($removable, $rescued));
        } while ($rescued !== []);

        return $removable;
    }

    /**
     * Everything the count still evaluates, as one string to search.
     */
    private function collectFilterSql(Select $countSelect): string
    {
        $fragments = [];

        foreach ([Select::WHERE, Select::HAVING] as $part) {
            foreach ((array) $countSelect->getPart($part) as $fragment) {
                $fragments[] = $this->toSql($fragment);
            }
        }

        foreach ((array) $countSelect->getPart(Select::COLUMNS) as $entry) {
            $fragments[] = $this->columnEntryToSql($entry);
        }

        return implode("\n", array_filter($fragments, static fn (string $sql): bool => $sql !== ''));
    }

    /**
     * A Zend column entry is [correlationName, column, alias]: the correlation name carries the
     * table reference for a plain column, and the expression carries it for anything else.
     */
    private function columnEntryToSql(mixed $entry): string
    {
        if (!is_array($entry)) {
            return $this->toSql($entry);
        }

        $correlationName = $this->toSql($entry[0] ?? '');
        $column = $this->toSql($entry[1] ?? '');

        return $correlationName === '' ? $column : $correlationName . '.' . $column;
    }

    /**
     * Both names a condition can use to reach a joined table.
     *
     * @param array<string, mixed> $joinSpec
     * @return string[]
     */
    private function identifiersFor(string $correlationName, array $joinSpec): array
    {
        $tableName = $this->toSql($joinSpec['tableName'] ?? '');

        return $tableName === '' || $tableName === $correlationName
            ? [$correlationName]
            : [$correlationName, $tableName];
    }

    /**
     * The joined table's column names, or null when they cannot be established — a derived table,
     * a table the connection cannot describe, a schema the admin user cannot read. Null means the
     * join stays.
     *
     * describeTable() answers from Magento's DDL cache after the first call, so the cost of this is
     * paid once per table per cache lifetime rather than once per grid page.
     *
     * @param array<string, mixed> $joinSpec
     * @return string[]|null
     */
    private function joinedTableColumns(array $joinSpec, AdapterInterface $connection): ?array
    {
        $tableName = $joinSpec['tableName'] ?? null;
        if (!is_string($tableName) || $tableName === '') {
            return null;
        }

        $schema = $joinSpec['schema'] ?? null;

        try {
            return array_keys($connection->describeTable($tableName, is_string($schema) ? $schema : null));
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Scr1be_AdminGridToolkit: cannot describe "%s", keeping its join.', $tableName),
                ['exception' => $e]
            );

            return null;
        }
    }

    /**
     * A false positive here — an identifier matched inside a string literal, say — keeps a join
     * that could have gone. That is the direction this check is allowed to be wrong in.
     */
    private function referencesIdentifier(string $identifier, string $sql): bool
    {
        if ($identifier === '' || $sql === '') {
            return false;
        }

        return (bool) preg_match(sprintf(self::IDENTIFIER_PATTERN, preg_quote($identifier, '/')), $sql);
    }

    /**
     * Removes every qualified reference, leaving only the names that stand on their own — which is
     * what the unqualified-column check needs to look at.
     */
    private function stripQualifiedReferences(string $sql): string
    {
        return (string) preg_replace(self::QUALIFIED_REFERENCE_PATTERN, ' ', $sql);
    }

    private function toSql(mixed $fragment): string
    {
        if (is_scalar($fragment)) {
            return (string) $fragment;
        }

        // Zend_Db_Expr and a nested Select both render themselves; PHP 8 marks them Stringable.
        return $fragment instanceof \Stringable ? (string) $fragment : '';
    }
}
