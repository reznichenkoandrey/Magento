<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Model;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use Scr1be\PosBridge\Api\CustomerLookupInterface;
use Scr1be\PosBridge\Api\Data\CustomerMatchInterface;
use Scr1be\PosBridge\Api\Data\CustomerMatchInterfaceFactory;
use Scr1be\PosBridge\Api\Data\CustomerSearchResultInterface;
use Scr1be\PosBridge\Api\Data\CustomerSearchResultInterfaceFactory;
use Scr1be\PosBridge\Model\ResourceModel\CustomerMatchQuery;
use Scr1be\PosBridge\Model\Search\CustomerColumns;
use Scr1be\PosBridge\Model\Search\QueryTokenizer;

/**
 * The decision ladder in front of the one query, and the mapping of its rows onto the contract.
 *
 * Order matters and each rung is a decision:
 *
 * 1. **Switched off** stops everything, before any input is looked at. A disabled bridge should not
 *    be distinguishable, by error message, from a bridge that dislikes your query.
 * 2. **Too short** is rejected rather than answered. A two-character query matches a meaningful
 *    slice of the customer table, and the result cap would turn that into an arbitrary answer that
 *    *looks* authoritative on a terminal screen.
 * 3. **An unknown website** is a 404 rather than an empty list. An empty list reads as "no such
 *    customer", and an operator who has been told that stops looking.
 * 4. Only then does the query run.
 */
class CustomerLookup implements CustomerLookupInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly QueryTokenizer $tokenizer,
        private readonly CustomerMatchQuery $matchQuery,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerMatchInterfaceFactory $matchFactory,
        private readonly CustomerSearchResultInterfaceFactory $resultFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function search(string $query, ?int $websiteId = null): CustomerSearchResultInterface
    {
        if (!$this->config->isEnabled()) {
            throw new LocalizedException(new Phrase('The POS bridge is switched off.'));
        }

        if (!$this->tokenizer->isLongEnough($query)) {
            throw new InputException(
                new Phrase(
                    'Enter at least %1 characters to look up a customer.',
                    [QueryTokenizer::MIN_QUERY_LENGTH]
                )
            );
        }

        if ($websiteId !== null) {
            // Throws NoSuchEntityException, which the REST layer renders as a 404.
            $this->storeManager->getWebsite($websiteId);
        }

        $tokens = $this->tokenizer->tokenize($query);
        if ($tokens === []) {
            return $this->resultFactory->create(['items' => [], 'hasMore' => false]);
        }

        $limit = $this->config->getResultLimit();
        $rows = $this->matchQuery->fetch($tokens, $websiteId, $limit);

        $hasMore = count($rows) > $limit;

        return $this->resultFactory->create([
            'items' => array_map(
                fn (array $row): CustomerMatchInterface => $this->toMatch($row),
                array_slice($rows, 0, $limit)
            ),
            'hasMore' => $hasMore,
        ]);
    }

    /**
     * @param array<string, string|null> $row
     */
    private function toMatch(array $row): CustomerMatchInterface
    {
        return $this->matchFactory->create([
            'customerId' => (int) $row[CustomerColumns::CUSTOMER_ID],
            'name' => (string) $this->joinName(
                $row[CustomerColumns::FIRSTNAME] ?? null,
                $row[CustomerColumns::LASTNAME] ?? null
            ),
            'email' => $this->nullableString($row[CustomerColumns::EMAIL] ?? null),
            'billingName' => $this->joinName(
                $row[CustomerColumns::BILLING_FIRSTNAME] ?? null,
                $row[CustomerColumns::BILLING_LASTNAME] ?? null
            ),
            'billingTelephone' => $this->nullableString($row[CustomerColumns::BILLING_TELEPHONE] ?? null),
            'websiteId' => isset($row[CustomerColumns::WEBSITE_ID])
                ? (int) $row[CustomerColumns::WEBSITE_ID]
                : null,
            'groupId' => (int) ($row[CustomerColumns::GROUP_ID] ?? 0),
        ]);
    }

    /**
     * A customer with no billing address joins to nulls, and a customer record can carry an empty
     * surname. Both have to come out as "there is no name here" rather than as a stray space.
     */
    private function joinName(?string $first, ?string $last): ?string
    {
        $name = trim(trim((string) $first) . ' ' . trim((string) $last));

        return $name === '' ? null : $name;
    }

    private function nullableString(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
