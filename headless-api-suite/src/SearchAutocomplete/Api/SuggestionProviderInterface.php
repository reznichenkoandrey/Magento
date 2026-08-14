<?php
declare(strict_types=1);

namespace Scr1be\SearchAutocomplete\Api;

use Scr1be\SearchAutocomplete\Model\SuggestionRequest;

/**
 * One source of autocomplete suggestions.
 *
 * @api
 */
interface SuggestionProviderInterface
{
    /**
     * Suggestions for one term, at most `$request->limit` of them.
     *
     * Implementations must not throw. One slow or broken source must degrade the drop-down, not
     * empty it — a search box that returns nothing because the popular-terms table is locked is a
     * worse outcome than a search box with no popular terms in it. The pool enforces this, but an
     * implementation that can answer partially should.
     *
     * @param SuggestionRequest $request
     * @return array<int, array<string, mixed>>
     */
    public function getSuggestions(SuggestionRequest $request): array;
}
