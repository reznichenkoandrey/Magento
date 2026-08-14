<?php
declare(strict_types=1);

namespace Scr1be\SearchAutocomplete\Model\Provider;

use Magento\Search\Model\ResourceModel\Query\CollectionFactory;
use Scr1be\SearchAutocomplete\Api\SuggestionProviderInterface;
use Scr1be\SearchAutocomplete\Model\SuggestionRequest;

/**
 * What other people searched for.
 *
 * Reads `search_query`, the table core fills on every storefront search, through
 * `Magento\Search\Model\ResourceModel\Query\Collection::setPopularQueryFilter()`. That method resets
 * the select onto `search_query`, applies a store filter, adds `num_results > 0` and orders by
 * `popularity DESC` — so the "did it find anything" filter and the ordering come from core rather
 * than from a hand-written select that would drift from it.
 *
 * `num_results > 0` is the part worth keeping: a term that has been searched a thousand times and
 * never matched anything is the *most* popular term in the table and the least useful suggestion
 * there is.
 */
class TermProvider implements SuggestionProviderInterface
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(private readonly CollectionFactory $collectionFactory)
    {
    }

    /**
     * @inheritDoc
     */
    public function getSuggestions(SuggestionRequest $request): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setPopularQueryFilter($request->storeId);
        $collection->addFieldToFilter(
            'query_text',
            ['like' => '%' . $this->escapeLike($request->term) . '%']
        );
        $collection->setPageSize($request->limit);
        $collection->setCurPage(1);

        $suggestions = [];
        foreach ($collection as $query) {
            $text = (string)$query->getData('query_text');
            $suggestions[] = [
                'query_text' => $text,
                'result_count' => (int)$query->getData('num_results'),
                // The term the shopper is already typing is not a suggestion. Flagging it rather than
                // dropping it lets a client show it first as "search for X" without a second query.
                'is_exact_match' => mb_strtolower($text) === mb_strtolower($request->term),
            ];
        }

        return $suggestions;
    }

    /**
     * @param string $term
     * @return string
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
