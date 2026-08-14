<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model\Source;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Scr1be\CuratedCategories\Api\CurationSourceInterface;
use Scr1be\CuratedCategories\Api\Data\CurationTargetInterface;
use Scr1be\CuratedCategories\Model\Config;
use Scr1be\CuratedCategories\Model\CurationTarget;

/**
 * Everything the three adapters share, which turns out to be everything except the query.
 *
 * A source's code is also its configuration group, so the base class can answer "am I on" and
 * "where do I write" for any adapter without knowing which one it is. Adding a fourth source is a
 * class with one method, a config group and a `di.xml` entry.
 */
abstract class AbstractSource implements CurationSourceInterface
{
    public function __construct(
        protected readonly Config $config,
        private readonly TimezoneInterface $localeDate
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->isSourceEnabled($this->getCode());
    }

    public function getTarget(): ?CurationTargetInterface
    {
        $categoryId = $this->config->getCategoryId($this->getCode());

        if ($categoryId <= 0) {
            return null;
        }

        return new CurationTarget(
            $categoryId,
            $this->config->getMinimumFloor($this->getCode()),
            $this->getCode()
        );
    }

    /**
     * The start of a rolling window, expressed in UTC.
     *
     * `sales_order.created_at` and this module's own `arrived_at` are TIMESTAMP columns, which MySQL
     * stores in UTC. The boundary is therefore computed from *now* in the configured locale — so
     * "30 days" means 30 days as the merchant experiences them — and then converted, rather than
     * formatted in whichever timezone the admin happens to run in and compared against UTC rows.
     */
    protected function getWindowStart(int $days): string
    {
        return $this->localeDate->date()
            ->modify(sprintf('-%d days', $days))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Midnight today in the configured locale, formatted the way EAV datetime values are stored.
     *
     * Product date attributes are not timestamps: `Magento\Eav\Model\Entity\Attribute\Backend\Datetime`
     * writes them into `catalog_product_entity_datetime`, and core compares them against a
     * locale-formatted string — see `Magento\Catalog\Block\Product\NewProduct::_getProductCollection()`,
     * which builds exactly this value for its `news_from_date` filter. Anything else here would be a
     * day out for half the year.
     */
    protected function getTodayStartOfDay(): string
    {
        return $this->localeDate->date()->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    }
}
