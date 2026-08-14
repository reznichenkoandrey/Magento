<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Scr1be\CuratedCategories\Model\Exclusion\Rule;

/**
 * The seven comparisons an exclusion row can make.
 *
 * The list is the single source of truth for the dropdown and for
 * `Scr1be\CuratedCategories\Model\Exclusion\RuleReader`, which discards any row whose operator is
 * not in it — so a value that cannot be chosen in the form also cannot be honoured if it turns up in
 * the database.
 */
class Operator implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Rule::OPERATOR_EQ, 'label' => (string) __('is')],
            ['value' => Rule::OPERATOR_NEQ, 'label' => (string) __('is not')],
            ['value' => Rule::OPERATOR_GT, 'label' => (string) __('greater than')],
            ['value' => Rule::OPERATOR_LT, 'label' => (string) __('less than')],
            ['value' => Rule::OPERATOR_IN, 'label' => (string) __('is one of (comma separated)')],
            ['value' => Rule::OPERATOR_NIN, 'label' => (string) __('is none of (comma separated)')],
            ['value' => Rule::OPERATOR_CONTAINS, 'label' => (string) __('contains')],
        ];
    }
}
