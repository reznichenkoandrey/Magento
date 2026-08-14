<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Scr1be\CuratedCategories\Model\Exclusion\RuleSet;

/**
 * How the exclusion rows combine. The labels spell the semantics out because "All" and "Any" on
 * their own are the two readings people most reliably get backwards.
 */
class MatchMode implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => RuleSet::MATCH_ANY, 'label' => (string) __('Any rule matches — exclude')],
            ['value' => RuleSet::MATCH_ALL, 'label' => (string) __('All rules match — exclude')],
        ];
    }
}
