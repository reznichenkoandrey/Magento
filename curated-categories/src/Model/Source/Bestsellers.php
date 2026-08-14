<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model\Source;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Scr1be\CuratedCategories\Model\Config;
use Scr1be\CuratedCategories\Model\ResourceModel\BestsellerRanking;

/**
 * "What sold best over the last N days", reconciled once a day.
 *
 * Daily rather than hourly because the ranking is a 30-day rolling window: an hour of trading moves
 * the top twenty-four by nothing a shopper would notice, and every run that reorders the category
 * writes rows into two mview changelogs. The cost of being fresher is paid by the reindex, not by
 * this query, which is why the schedule is the conservative end of what the data supports.
 */
class Bestsellers extends AbstractSource
{
    public const CODE = 'bestsellers';

    public function __construct(
        Config $config,
        TimezoneInterface $localeDate,
        private readonly BestsellerRanking $ranking
    ) {
        parent::__construct($config, $localeDate);
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getProductIds(): array
    {
        return $this->ranking->getTopProductIds(
            $this->getWindowStart($this->config->getWindowDays(self::CODE)),
            $this->config->getLimit(self::CODE)
        );
    }
}
