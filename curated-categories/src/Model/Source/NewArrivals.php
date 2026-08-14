<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model\Source;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Scr1be\CuratedCategories\Model\Config;
use Scr1be\CuratedCategories\Model\Exclusion\ProductFilter;
use Scr1be\CuratedCategories\Model\Exclusion\RuleReader;
use Scr1be\CuratedCategories\Model\ResourceModel\ArrivalIndex;

/**
 * "What arrived in the last N days", where arrival means the first time a product was in stock.
 *
 * This is the one adapter with two clocks. The observer on the stock item puts a product on the New
 * page within a request of it becoming buyable, because "new" is worth nothing an hour late; the
 * hourly cron reconciles the whole feed, because an event-driven membership list is only ever as
 * correct as its least reliable event. Products age out, an import runs with events disabled, a
 * deploy eats a request — the self-heal run makes all three invisible.
 *
 * ## Over-fetching before exclusion
 *
 * Exclusion rules are evaluated on the products the query already picked, which means a rule that
 * matches half of them halves the feed. The query therefore asks for several times the limit and
 * trims afterwards. It is a heuristic, not a guarantee: a rule set that excludes more than
 * {@see OVERFETCH_FACTOR} in every window will still produce a short feed, and at that point the
 * floor guard is what keeps the page from emptying. Filtering in SQL instead would remove the
 * heuristic and put seven operators' worth of comparison semantics into a left-joined EAV query —
 * see `Scr1be\CuratedCategories\Model\Exclusion\ProductFilter` for why that trade was refused.
 */
class NewArrivals extends AbstractSource
{
    public const CODE = 'new_arrivals';

    /**
     * How many candidates to pull per slot in the feed. Three covers an exclusion set that rejects
     * two out of three arrivals, which is already an unusual amount of excluding.
     */
    private const OVERFETCH_FACTOR = 3;

    public function __construct(
        Config $config,
        TimezoneInterface $localeDate,
        private readonly ArrivalIndex $arrivalIndex,
        private readonly RuleReader $ruleReader,
        private readonly ProductFilter $productFilter
    ) {
        parent::__construct($config, $localeDate);
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getProductIds(): array
    {
        $limit = $this->config->getLimit(self::CODE);

        $candidates = $this->arrivalIndex->getRecentArrivals(
            $this->getWindowStart($this->config->getWindowDays(self::CODE)),
            $limit * self::OVERFETCH_FACTOR
        );

        $surviving = $this->productFilter->apply($candidates, $this->ruleReader->read(self::CODE));

        return array_slice($surviving, 0, $limit);
    }

    /**
     * Whether a single product belongs on the New page right now.
     *
     * The observer's question, answered against exactly the same window and the same rule set the
     * full reconcile uses — if the two disagreed, every incremental add would be undone by the next
     * hourly run, or worse, survive it.
     */
    public function qualifies(int $productId): bool
    {
        $arrivedAt = $this->arrivalIndex->getArrivalDate($productId);

        if ($arrivedAt === null
            || $arrivedAt < $this->getWindowStart($this->config->getWindowDays(self::CODE))) {
            return false;
        }

        return $this->productFilter->apply([$productId], $this->ruleReader->read(self::CODE)) !== [];
    }
}
