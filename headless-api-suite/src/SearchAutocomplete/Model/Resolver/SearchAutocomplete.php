<?php
declare(strict_types=1);

namespace Scr1be\SearchAutocomplete\Model\Resolver;

use Magento\Customer\Model\Group;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\SearchAutocomplete\Model\Config;
use Scr1be\SearchAutocomplete\Model\ProviderPool;
use Scr1be\SearchAutocomplete\Model\SuggestionRequest;

/**
 * `searchAutocomplete(query:)` — one round trip for the whole drop-down.
 *
 * The reason this is one query rather than three is latency, not tidiness. An app firing
 * `products`, `categories` and `searchTerms` separately on each keystroke pays three TLS round trips
 * and three Magento bootstraps for one drop-down; on a phone on mobile data that is the difference
 * between an autocomplete that feels instant and one that feels broken.
 *
 * A term shorter than the store's minimum returns empty sections rather than an error. The client is
 * typing — being told off for having typed two letters so far is not useful, and the schema's
 * non-null lists mean "empty" is a perfectly good answer.
 */
class SearchAutocomplete implements ResolverInterface
{
    /**
     * @param ProviderPool $providerPool
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ProviderPool $providerPool,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $store = $context->getExtensionAttributes()->getStore();
        $storeId = $store instanceof StoreInterface ? (int)$store->getId() : (int)$this->storeManager->getStore()->getId();

        $term = trim((string)($args['query'] ?? ''));
        $term = mb_substr($term, 0, $this->config->getMaxQueryLength($storeId));

        if (mb_strlen($term) < $this->config->getMinQueryLength($storeId)) {
            return $this->emptyResult($term);
        }

        $request = new SuggestionRequest(
            $term,
            $storeId,
            (int)$this->storeManager->getStore($storeId)->getWebsiteId(),
            $this->customerGroupId($context),
            $this->config->getLimit($storeId)
        );

        return ['query' => $term] + $this->providerPool->collect($request);
    }

    /**
     * The group whose prices the cards should show.
     *
     * `customer_group_id` is an extension attribute of the GraphQL context declared by
     * `Magento_CustomerGraphQl` (`etc/extension_attributes.xml`). It is the only source for this in
     * a headless request — there is no storefront session to fall back on — so an absent value means
     * an unauthenticated caller, which is NOT LOGGED IN.
     *
     * @param mixed $context
     * @return int
     */
    private function customerGroupId($context): int
    {
        $groupId = $context->getExtensionAttributes()->getCustomerGroupId();

        return $groupId === null ? Group::NOT_LOGGED_IN_ID : (int)$groupId;
    }

    /**
     * @param string $term
     * @return array<string, mixed>
     */
    private function emptyResult(string $term): array
    {
        $result = ['query' => $term];
        foreach ($this->providerPool->getKeys() as $key) {
            $result[$key] = [];
        }

        return $result;
    }
}
