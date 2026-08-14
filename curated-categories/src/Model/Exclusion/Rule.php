<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model\Exclusion;

/**
 * One admin-typed exclusion: attribute, operator, value.
 *
 * Evaluation is deliberately loose about types, because the values it compares come from two
 * directions that never agree on them. On one side the admin types into a text box, so everything
 * arrives as a string; on the other, product attribute values come back as strings for varchar, as
 * numeric strings for decimal, and as option *ids* for selects and swatches. Comparing `"42"` to
 * `42` with `===` would make every numeric rule silently match nothing, which is the worst possible
 * outcome for a rule whose job is to keep products off a page.
 *
 * The comparison rules, therefore:
 *
 * - `eq` / `neq` compare numerically when both sides look numeric, and as trimmed strings otherwise.
 * - `gt` / `lt` are numeric only; a non-numeric operand makes the rule not match rather than
 *   coercing "Blue" to zero and quietly excluding everything.
 * - `in` / `nin` split the admin's value on commas, which is how a multi-value field is typed when
 *   the form gives you one box.
 * - `contains` is a case-insensitive substring test, for the "anything with SAMPLE in the sku" rule
 *   that every catalogue eventually needs.
 *
 * A missing attribute value is never a match, except for `neq` and `nin`, where "the product does
 * not have this value" is exactly what the merchant asked about.
 */
class Rule
{
    public const OPERATOR_EQ = 'eq';
    public const OPERATOR_NEQ = 'neq';
    public const OPERATOR_GT = 'gt';
    public const OPERATOR_LT = 'lt';
    public const OPERATOR_IN = 'in';
    public const OPERATOR_NIN = 'nin';
    public const OPERATOR_CONTAINS = 'contains';

    public const OPERATORS = [
        self::OPERATOR_EQ,
        self::OPERATOR_NEQ,
        self::OPERATOR_GT,
        self::OPERATOR_LT,
        self::OPERATOR_IN,
        self::OPERATOR_NIN,
        self::OPERATOR_CONTAINS,
    ];

    public function __construct(
        private readonly string $attributeCode,
        private readonly string $operator,
        private readonly string $value
    ) {
    }

    public function getAttributeCode(): string
    {
        return $this->attributeCode;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * @param mixed $attributeValue Whatever the product carries for this attribute; null when unset.
     */
    public function matches(mixed $attributeValue): bool
    {
        $isAbsent = $attributeValue === null || $attributeValue === '';

        return match ($this->operator) {
            self::OPERATOR_EQ => !$isAbsent && $this->looselyEquals($attributeValue),
            self::OPERATOR_NEQ => $isAbsent || !$this->looselyEquals($attributeValue),
            self::OPERATOR_GT => $this->compareNumerically($attributeValue, static fn ($a, $b) => $a > $b),
            self::OPERATOR_LT => $this->compareNumerically($attributeValue, static fn ($a, $b) => $a < $b),
            self::OPERATOR_IN => !$isAbsent && $this->isInList($attributeValue),
            self::OPERATOR_NIN => $isAbsent || !$this->isInList($attributeValue),
            self::OPERATOR_CONTAINS => !$isAbsent && $this->containsNeedle($attributeValue),
            default => false,
        };
    }

    private function looselyEquals(mixed $attributeValue): bool
    {
        $left = trim((string) $attributeValue);
        $right = trim($this->value);

        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left === (float) $right;
        }

        return $left === $right;
    }

    /**
     * @param callable(float, float): bool $comparator
     */
    private function compareNumerically(mixed $attributeValue, callable $comparator): bool
    {
        $left = is_scalar($attributeValue) ? trim((string) $attributeValue) : '';
        $right = trim($this->value);

        if (!is_numeric($left) || !is_numeric($right)) {
            return false;
        }

        return $comparator((float) $left, (float) $right);
    }

    private function isInList(mixed $attributeValue): bool
    {
        $needle = trim((string) $attributeValue);

        foreach ($this->splitValue() as $candidate) {
            if ($candidate === $needle || (is_numeric($candidate) && is_numeric($needle)
                && (float) $candidate === (float) $needle)) {
                return true;
            }
        }

        return false;
    }

    private function containsNeedle(mixed $attributeValue): bool
    {
        $needle = trim($this->value);

        if ($needle === '') {
            return false;
        }

        return stripos((string) $attributeValue, $needle) !== false;
    }

    /**
     * @return string[]
     */
    private function splitValue(): array
    {
        $parts = array_map('trim', explode(',', $this->value));

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
