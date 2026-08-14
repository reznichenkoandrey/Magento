<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\ProductSource;

use Magento\Framework\Exception\LocalizedException;
use Scr1be\HyvaProductSlider\Api\ProductSourceInterface;

/**
 * The registry of product sources, filled from `di.xml`.
 *
 * A pool rather than a `switch`: a project that needs a tenth way of choosing products adds one
 * class and one `<item>`, and touches nothing that already works. Everything downstream — the admin
 * dropdown, the validator, the frontend provider — reads the pool, so a source that is registered is
 * automatically offered, validated and renderable.
 */
class Pool
{
    /** @var array<string, ProductSourceInterface> */
    private array $sources;

    /**
     * @param array<string, ProductSourceInterface> $sources
     * @throws \InvalidArgumentException When di.xml registers something that is not a source.
     */
    public function __construct(array $sources = [])
    {
        foreach ($sources as $code => $source) {
            if (!$source instanceof ProductSourceInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Product source "%s" must implement %s.', $code, ProductSourceInterface::class)
                );
            }
        }

        $this->sources = $sources;
    }

    /**
     * @return array<string, ProductSourceInterface> Only the ones whose backing data exists.
     */
    public function getAvailable(): array
    {
        return array_filter($this->sources, static fn (ProductSourceInterface $source): bool => $source->isAvailable());
    }

    public function has(string $code): bool
    {
        return isset($this->sources[$code]);
    }

    /**
     * @throws LocalizedException
     */
    public function get(string $code): ProductSourceInterface
    {
        if (!isset($this->sources[$code])) {
            throw new LocalizedException(__('Unknown product source "%1".', $code));
        }

        return $this->sources[$code];
    }

    /**
     * Resolution that never throws, for the storefront.
     *
     * A slider whose source module was disabled after the slider was created must render as nothing
     * — not as an exception on a category page. The admin side uses {@see get()} instead, where the
     * merchandiser is the right person to see the error.
     */
    public function find(string $code): ?ProductSourceInterface
    {
        $source = $this->sources[$code] ?? null;

        return $source !== null && $source->isAvailable() ? $source : null;
    }
}
