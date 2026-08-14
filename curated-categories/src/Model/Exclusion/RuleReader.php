<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model\Exclusion;

use Scr1be\CuratedCategories\Model\Config;

/**
 * Turns the dynamic-rows blob into a rule set, dropping anything it cannot make sense of.
 *
 * The form is three free-ish text inputs per row, saved through
 * `Magento\Config\Model\Config\Backend\Serialized\ArraySerialized`, so the reader is the boundary
 * between "whatever ended up in core_config_data" and a typed object the rest of the module can
 * trust. Rows without an attribute code, and rows with an operator that is not one of the seven, are
 * discarded rather than defaulted: an exclusion rule that quietly becomes a different rule is worse
 * than one that is not there, because the merchant will believe the products are excluded.
 */
class RuleReader
{
    public function __construct(private readonly Config $config)
    {
    }

    public function read(string $sourceCode): RuleSet
    {
        $rules = [];

        foreach ($this->config->getExclusionRules($sourceCode) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $attributeCode = trim((string) ($row['attribute'] ?? ''));
            $operator = trim((string) ($row['operator'] ?? ''));

            if ($attributeCode === '' || !in_array($operator, Rule::OPERATORS, true)) {
                continue;
            }

            $rules[] = new Rule($attributeCode, $operator, (string) ($row['value'] ?? ''));
        }

        return new RuleSet($rules, $this->readMatchMode($sourceCode));
    }

    /**
     * Any is the fallback for an unrecognised mode, because it is the reading that excludes more.
     * If the setting is corrupt, keeping a product off a curated page is recoverable in a click and
     * putting a product the merchant banned onto one is not.
     */
    private function readMatchMode(string $sourceCode): string
    {
        $mode = strtolower($this->config->getExclusionMatchMode($sourceCode));

        return $mode === RuleSet::MATCH_ALL ? RuleSet::MATCH_ALL : RuleSet::MATCH_ANY;
    }
}
