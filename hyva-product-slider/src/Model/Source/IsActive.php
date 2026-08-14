<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Status options for the grid filter and its select column.
 *
 * Values are the integers the column stores, not booleans: the grid round-trips them through a URL
 * and a bookmark, where `false` and `"0"` are not the same thing.
 */
class IsActive implements OptionSourceInterface
{
    public const ENABLED = 1;
    public const DISABLED = 0;

    /**
     * @return array<int, array{value: int, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::ENABLED, 'label' => __('Enabled')],
            ['value' => self::DISABLED, 'label' => __('Disabled')],
        ];
    }
}
