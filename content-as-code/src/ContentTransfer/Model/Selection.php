<?php
declare(strict_types=1);

namespace Scr1be\ContentTransfer\Model;

/**
 * What the operator asked to capture: a store filter plus, optionally, an explicit list of
 * identifiers per porter.
 *
 * Both filters are additive-empty — an empty store list means every store view, an absent porter
 * entry means every identifier that porter can see. That inverts nicely on the command line, where
 * `content:capture` with no options is the "give me everything" people actually type first.
 */
class Selection
{
    /**
     * @param int[] $storeIds Resolved store view ids; empty means no store filter at all.
     * @param array<string, string[]> $identifiers porter code => identifiers, or an absent key for
     *        "every identifier this porter has".
     */
    public function __construct(
        private readonly array $storeIds = [],
        private readonly array $identifiers = []
    ) {
    }

    /**
     * @return int[]
     */
    public function getStoreIds(): array
    {
        return $this->storeIds;
    }

    public function hasStoreFilter(): bool
    {
        return $this->storeIds !== [];
    }

    /**
     * Porter codes the operator asked for, or an empty array for "every porter in the pool".
     *
     * @return string[]
     */
    public function getPorterCodes(): array
    {
        return array_keys($this->identifiers);
    }

    public function includesPorter(string $porterCode): bool
    {
        return $this->identifiers === [] || array_key_exists($porterCode, $this->identifiers);
    }

    /**
     * @return string[] Empty means "no identifier filter for this porter".
     */
    public function getIdentifiers(string $porterCode): array
    {
        return $this->identifiers[$porterCode] ?? [];
    }

    public function includesIdentifier(string $porterCode, string $identifier): bool
    {
        $wanted = $this->getIdentifiers($porterCode);

        return $wanted === [] || in_array($identifier, $wanted, true);
    }
}
