<?php
declare(strict_types=1);

namespace Scr1be\SearchAutocomplete\Model;

use Psr\Log\LoggerInterface;
use Scr1be\SearchAutocomplete\Api\SuggestionProviderInterface;

/**
 * Runs every configured provider and returns their results keyed by the schema field they fill.
 *
 * The catch-all is the reason this is a class rather than three calls in the resolver. A drop-down
 * that goes blank because the popular-terms table happened to be locked is a worse failure than a
 * drop-down with no popular terms in it, and the shopper cannot tell the difference between "no
 * results" and "something broke". So one provider's exception costs its own section and nothing
 * else, and the operator finds out from the log.
 */
class ProviderPool
{
    /**
     * @var array<string, SuggestionProviderInterface>
     */
    private array $providers;

    /**
     * @param LoggerInterface $logger
     * @param SuggestionProviderInterface[] $providers Keyed by the result field they populate.
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        array $providers = []
    ) {
        foreach ($providers as $key => $provider) {
            if (!$provider instanceof SuggestionProviderInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Autocomplete provider "%s" must implement %s.', $key, SuggestionProviderInterface::class)
                );
            }
        }

        $this->providers = $providers;
    }

    /**
     * @param SuggestionRequest $request
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function collect(SuggestionRequest $request): array
    {
        $results = [];

        foreach ($this->providers as $key => $provider) {
            try {
                $results[$key] = $provider->getSuggestions($request);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Scr1be_SearchAutocomplete: provider "%s" failed: %s', $key, $e->getMessage()),
                    ['exception' => $e]
                );
                $results[$key] = [];
            }
        }

        return $results;
    }

    /**
     * The field names this pool can fill, so the resolver can return an empty section for each rather
     * than omitting keys the schema declares as non-null lists.
     *
     * @return string[]
     */
    public function getKeys(): array
    {
        return array_keys($this->providers);
    }
}
