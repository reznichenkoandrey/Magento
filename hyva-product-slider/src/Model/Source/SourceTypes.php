<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Scr1be\HyvaProductSlider\Api\ProductSourceInterface;
use Scr1be\HyvaProductSlider\Model\ProductSource\Pool;

/**
 * The source dropdown, built from the pool rather than from a list.
 *
 * Two consequences, both intended: a project that registers a tenth source gets it in the admin for
 * free, and a source whose backing module is disabled never appears — `Pool::getAvailable()` filters
 * on `isAvailable()`. Offering "Most Viewed" on a shop with Reports switched off would be a dropdown
 * entry whose only possible outcome is an empty carousel.
 */
class SourceTypes implements OptionSourceInterface
{
    public function __construct(private readonly Pool $pool)
    {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];

        foreach ($this->pool->getAvailable() as $code => $source) {
            /** @var ProductSourceInterface $source */
            $options[] = ['value' => (string) $code, 'label' => $source->getLabel()];
        }

        return $options;
    }
}
