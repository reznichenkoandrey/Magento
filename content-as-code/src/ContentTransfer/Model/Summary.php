<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model;

/**
 * One row in the admin picker: enough to recognise an entity, not enough to be worth capturing.
 *
 * The picker lists everything a store has; capturing everything a store has, only to throw away the
 * rows the operator did not tick, would make opening the page as expensive as an export.
 */
class Summary
{
    /**
     * @param string[] $storeCodes Empty means "all store views" — the way core stores store id 0.
     */
    public function __construct(
        private readonly string $identifier,
        private readonly string $label,
        private readonly array $storeCodes = []
    ) {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return string[]
     */
    public function getStoreCodes(): array
    {
        return $this->storeCodes;
    }
}
