<?php
declare(strict_types=1);

namespace Scr1be\AdminGridToolkit\Plugin\Grid;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Select;
use Scr1be\AdminGridToolkit\Model\Config;
use Scr1be\AdminGridToolkit\Model\Grid\CountSelectDejoiner;

/**
 * Hands the pager's count select to the de-joiner.
 *
 * `after` on getSelectCountSql() rather than around getSize(): the select core builds is exactly
 * the input this needs, the method is a pure builder with no side effects to preserve, and leaving
 * the query's execution to core means a collection that overrides how it counts keeps doing so.
 *
 * The subject is typed as the framework collection rather than the sales grid collection this is
 * wired to. Nothing here is order-specific — pointing it at the invoice, shipment or credit-memo
 * grid is one more <type> block and its own allowlist.
 */
class DejoinGridCount
{
    public function __construct(
        private readonly Config $config,
        private readonly CountSelectDejoiner $dejoiner
    ) {
    }

    /**
     * getSelectCountSql() is documented as returning a Select, but it is untyped in core and a
     * collection is free to answer with a raw SQL string it assembled itself. There is no surgery
     * to perform on a string, so anything that is not a Select passes through.
     *
     * @param mixed $result
     * @return mixed
     */
    public function afterGetSelectCountSql(AbstractDb $subject, $result)
    {
        if (!$result instanceof Select || !$this->config->isGridCountDejoinEnabled()) {
            return $result;
        }

        return $this->dejoiner->stripUnusedJoins($result, $subject->getConnection());
    }
}
