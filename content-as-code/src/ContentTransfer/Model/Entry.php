<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model;

use Scr1be\ContentTransfer\Api\Data\EntryInterface;

/**
 * Immutable carrier for one captured entity.
 *
 * Transforms and warnings ride along with the entry rather than being collected in a side channel,
 * because the operator's question is always "what happened to *this* page", never "what happened
 * overall".
 */
class Entry implements EntryInterface
{
    /**
     * @param array<string, mixed> $payload
     * @param string[] $transforms
     * @param string[] $warnings
     */
    public function __construct(
        private readonly string $porterCode,
        private readonly string $identifier,
        private readonly array $payload,
        private readonly array $transforms = [],
        private readonly array $warnings = []
    ) {
    }

    public function getPorterCode(): string
    {
        return $this->porterCode;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getTransforms(): array
    {
        return $this->transforms;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }
}
