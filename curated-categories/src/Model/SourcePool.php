<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Scr1be\CuratedCategories\Api\CurationSourceInterface;

/**
 * The registry of adapters, populated from `di.xml`.
 *
 * The pool validates its own contents on construction rather than at use: a wrong entry in `di.xml`
 * is a deployment mistake, and the useful moment to hear about it is the first time anything asks
 * for a source, not the first time cron happens to select the broken one at three in the morning.
 */
class SourcePool
{
    /** @var array<string, CurationSourceInterface> */
    private array $sources;

    /**
     * @param CurationSourceInterface[] $sources Keyed by source code in di.xml.
     * @throws LocalizedException
     */
    public function __construct(array $sources = [])
    {
        foreach ($sources as $code => $source) {
            if (!$source instanceof CurationSourceInterface) {
                throw new LocalizedException(
                    new Phrase(
                        'Curated category source "%1" must implement %2.',
                        [(string) $code, CurationSourceInterface::class]
                    )
                );
            }
        }

        $this->sources = $sources;
    }

    /**
     * @return string[]
     */
    public function getCodes(): array
    {
        return array_keys($this->sources);
    }

    /**
     * @return CurationSourceInterface[] Keyed by code.
     */
    public function getAll(): array
    {
        return $this->sources;
    }

    /**
     * @return CurationSourceInterface[] Keyed by code.
     */
    public function getEnabled(): array
    {
        return array_filter(
            $this->sources,
            static fn (CurationSourceInterface $source): bool => $source->isEnabled()
        );
    }

    public function has(string $code): bool
    {
        return isset($this->sources[$code]);
    }

    /**
     * @throws LocalizedException When the code is not registered — a CLI typo, in practice.
     */
    public function get(string $code): CurationSourceInterface
    {
        if (!isset($this->sources[$code])) {
            throw new LocalizedException(
                new Phrase(
                    'Unknown curated category source "%1". Known sources: %2.',
                    [$code, implode(', ', $this->getCodes())]
                )
            );
        }

        return $this->sources[$code];
    }
}
