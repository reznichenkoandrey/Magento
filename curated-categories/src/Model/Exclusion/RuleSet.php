<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model\Exclusion;

/**
 * A set of rules plus the one thing that decides what the set means: All or Any.
 *
 * **Any** — the product is excluded if a single rule matches. This is the union of several
 * unrelated bans ("no gift cards, no samples, nothing from the clearance set").
 *
 * **All** — the product is excluded only when every rule matches. This is one compound ban with
 * several conditions ("exclude the discontinued items *that are also* out of stock").
 *
 * An empty set excludes nothing, under either mode. That is not the vacuous-truth reading of "all"
 * — it is a deliberate override, because the alternative is that saving the form with no rules
 * empties the category, which is precisely the misconfiguration the engine's guard exists to catch
 * and a much better thing to not do in the first place.
 */
class RuleSet
{
    public const MATCH_ALL = 'all';
    public const MATCH_ANY = 'any';

    /**
     * @param Rule[] $rules
     */
    public function __construct(
        private readonly array $rules,
        private readonly string $matchMode
    ) {
    }

    /**
     * @return Rule[]
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    public function getMatchMode(): string
    {
        return $this->matchMode;
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    /**
     * @return string[] Attribute codes the set needs loaded, deduplicated.
     */
    public function getAttributeCodes(): array
    {
        $codes = [];

        foreach ($this->rules as $rule) {
            $codes[$rule->getAttributeCode()] = true;
        }

        return array_keys($codes);
    }

    /**
     * @param array<string, mixed> $attributeValues Keyed by attribute code.
     */
    public function excludes(array $attributeValues): bool
    {
        if ($this->rules === []) {
            return false;
        }

        foreach ($this->rules as $rule) {
            $matched = $rule->matches($attributeValues[$rule->getAttributeCode()] ?? null);

            if ($this->matchMode === self::MATCH_ANY && $matched) {
                return true;
            }

            if ($this->matchMode !== self::MATCH_ANY && !$matched) {
                return false;
            }
        }

        // Fell through: under Any nothing matched, under All everything did.
        return $this->matchMode !== self::MATCH_ANY;
    }
}
