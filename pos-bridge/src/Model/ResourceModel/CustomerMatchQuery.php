<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Scr1be\PosBridge\Model\Search\CustomerColumns;
use Scr1be\PosBridge\Model\Search\MatchConditionBuilder;
use Scr1be\PosBridge\Model\Search\Token;

/**
 * The one query the module runs.
 *
 * It is a hand-built `Select` over two tables rather than a customer collection, and that is the
 * central architectural decision of the search half. Everything it needs is a plain column:
 * `customer_entity` carries `email`, `firstname`, `lastname`, `website_id`, `group_id` and
 * `default_billing` as real columns, and `customer_address_entity` carries `firstname`, `lastname`
 * and `telephone` the same way — both verified against `Magento_Customer`'s `db_schema.xml`. Nothing
 * here lives in an EAV value table.
 *
 * Going through `CustomerRepositoryInterface::getList()` would have meant accepting the joins core
 * adds for it — `addNameToSelect()` plus six `joinAttribute()` calls for billing postcode, city,
 * telephone, region, country and company — none of which this screen shows, and still would not have
 * reached billing *name*, which core does not join. It would also have hydrated a full customer data
 * model per row when the terminal displays four fields. One join, nine columns, one round trip.
 *
 * The billing side joins the **default** billing address only. Joining every address a customer owns
 * would multiply rows and need a DISTINCT, and would let a search match against an address the shop
 * has no reason to consider current.
 */
class CustomerMatchQuery
{
    private const CUSTOMER_TABLE = 'customer_entity';
    private const ADDRESS_TABLE = 'customer_address_entity';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MatchConditionBuilder $conditionBuilder
    ) {
    }

    /**
     * @param Token[] $tokens Every one of them must match — they are AND-ed.
     * @param int|null $websiteId
     * @param int $limit The caller's cap. One extra row is fetched so the caller can tell whether
     *                   the cap was reached without paying for a second COUNT over the same join.
     * @return array<int, array<string, string|null>> Raw rows keyed by {@see CustomerColumns}.
     */
    public function fetch(array $tokens, ?int $websiteId, int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $customerAlias = CustomerColumns::CUSTOMER_ALIAS;
        $billingAlias = CustomerColumns::BILLING_ALIAS;

        $select = $connection->select()
            ->from(
                [$customerAlias => $this->resourceConnection->getTableName(self::CUSTOMER_TABLE)],
                [
                    CustomerColumns::CUSTOMER_ID => 'entity_id',
                    CustomerColumns::FIRSTNAME => 'firstname',
                    CustomerColumns::LASTNAME => 'lastname',
                    CustomerColumns::EMAIL => 'email',
                    CustomerColumns::WEBSITE_ID => 'website_id',
                    CustomerColumns::GROUP_ID => 'group_id',
                ]
            )
            ->joinLeft(
                [$billingAlias => $this->resourceConnection->getTableName(self::ADDRESS_TABLE)],
                sprintf('%s.entity_id = %s.default_billing', $billingAlias, $customerAlias),
                [
                    CustomerColumns::BILLING_FIRSTNAME => 'firstname',
                    CustomerColumns::BILLING_LASTNAME => 'lastname',
                    CustomerColumns::BILLING_TELEPHONE => 'telephone',
                ]
            );

        // `Select::TYPE_CONDITION` is not decoration. `Magento\Framework\DB\Select::where()` turns a
        // null value into an empty string when no type is given, and `Zend_Db_Select::_where()` then
        // runs the condition through `quoteInto()` — which is `str_replace('?', …)`. A shopper
        // searched for as `who?` would have the question mark in the already-built LIKE pattern
        // rewritten, and the search would quietly look for something else. Passing TYPE_CONDITION is
        // how core itself hands a pre-built condition to a select; see
        // `Eav\Model\Entity\Collection\AbstractCollection::addAttributeToFilter()`.
        foreach ($tokens as $token) {
            $select->where(
                $this->conditionBuilder->forToken($connection, $token),
                null,
                Select::TYPE_CONDITION
            );
        }

        if ($websiteId !== null) {
            $select->where(sprintf('%s.website_id = ?', $customerAlias), $websiteId);
        }

        // A LIMIT without an ORDER BY returns whichever rows the storage engine reached first, which
        // makes two identical searches disagree. Surname first is what a paper customer book was
        // sorted by and what an operator scans; entity_id last only breaks ties deterministically.
        $select->order(sprintf('%s.lastname ASC', $customerAlias))
            ->order(sprintf('%s.firstname ASC', $customerAlias))
            ->order(sprintf('%s.entity_id ASC', $customerAlias))
            ->limit($limit + 1);

        return $connection->fetchAll($select);
    }
}
