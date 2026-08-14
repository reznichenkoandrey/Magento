<?php
declare(strict_types=1);

namespace Scr1be\PosBridge\Api;

use Scr1be\PosBridge\Api\Data\CustomerSearchResultInterface;

/**
 * Finds the customer standing at the counter from whatever they said.
 *
 * The one input is a free-text query, because that is the only input a terminal has: the operator
 * types what they hear. Splitting it into structured fields would move the parsing problem from
 * this module into every terminal that talks to it.
 *
 * @api
 */
interface CustomerLookupInterface
{
    /**
     * Search customers by free text, optionally scoped to one website.
     *
     * @param string $query Whitespace-separated terms. Every term must match something; a term of
     *                      three or more digits also matches the billing phone number.
     * @param int|null $websiteId Restrict to one website. Omit to search the whole installation.
     * @return \Scr1be\PosBridge\Api\Data\CustomerSearchResultInterface
     * @throws \Magento\Framework\Exception\InputException When the query is too short to be useful.
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the website does not exist.
     * @throws \Magento\Framework\Exception\LocalizedException When the bridge is switched off.
     */
    public function search(string $query, ?int $websiteId = null): CustomerSearchResultInterface;
}
