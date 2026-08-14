<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model;

use Scr1be\ContentTransfer\Api\Data\EntryInterface;
use Scr1be\ContentTransfer\Model\Bundle\Manifest;

/**
 * A manifest and the entries it describes — the in-memory form of one bundle file.
 */
class Bundle
{
    /**
     * @param EntryInterface[] $entries
     */
    public function __construct(
        private readonly Manifest $manifest,
        private readonly array $entries
    ) {
    }

    public function getManifest(): Manifest
    {
        return $this->manifest;
    }

    /**
     * @return EntryInterface[]
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * @return EntryInterface[]
     */
    public function getEntriesFor(string $porterCode): array
    {
        return array_values(
            array_filter(
                $this->entries,
                static fn (EntryInterface $entry): bool => $entry->getPorterCode() === $porterCode
            )
        );
    }

    /**
     * Porter codes present in the entries, in the order they first appear.
     *
     * Read from the entries rather than from the manifest counts: the entries are the payload, the
     * manifest is a description of it, and a hand-edited bundle can disagree.
     *
     * @return string[]
     */
    public function getPorterCodes(): array
    {
        $codes = [];

        foreach ($this->entries as $entry) {
            $codes[$entry->getPorterCode()] = true;
        }

        return array_keys($codes);
    }
}
