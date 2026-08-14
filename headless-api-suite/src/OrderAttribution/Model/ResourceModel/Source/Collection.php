<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Model\ResourceModel\Source;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Scr1be\OrderAttribution\Model\ResourceModel\Source as SourceResource;
use Scr1be\OrderAttribution\Model\Source;

/**
 * Source registry collection.
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'source_id';

    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(Source::class, SourceResource::class);
    }
}
