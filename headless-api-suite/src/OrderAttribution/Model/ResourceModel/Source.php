<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for the source registry.
 */
class Source extends AbstractDb
{
    public const TABLE = 'scr1be_order_source';

    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'source_id');
    }
}
