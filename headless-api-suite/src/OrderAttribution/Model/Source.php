<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Model;

use Magento\Framework\Model\AbstractModel;
use Scr1be\OrderAttribution\Api\Data\SourceInterface;
use Scr1be\OrderAttribution\Model\ResourceModel\Source as SourceResource;

/**
 * Registry row.
 */
class Source extends AbstractModel implements SourceInterface
{
    /**
     * Tag shared with the `availableOrderSources` resolver cache.
     *
     * The pairing is core's own: `Magento\Cms\Model\Block` declares `CACHE_TAG` and sets
     * `$_cacheTag` to it, and `Magento\CmsGraphQl\Model\Resolver\Block\Identity` returns the same
     * string. `AbstractModel::afterSave()` calls `cleanModelCache()`, which cleans `$_cacheTag`, so
     * editing a source in the admin purges the cached query without any invalidation code of its
     * own.
     */
    public const CACHE_TAG = 'scr1be_order_source';

    /**
     * @var string
     */
    protected $_cacheTag = self::CACHE_TAG;

    /**
     * @var string
     */
    protected $_eventPrefix = 'scr1be_order_source';

    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(SourceResource::class);
    }

    /**
     * @inheritDoc
     */
    public function getSourceId(): ?int
    {
        $value = $this->getData(self::SOURCE_ID);

        return $value === null ? null : (int)$value;
    }

    /**
     * @inheritDoc
     */
    public function setSourceId(?int $sourceId): SourceInterface
    {
        return $this->setData(self::SOURCE_ID, $sourceId);
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return (string)$this->getData(self::CODE);
    }

    /**
     * @inheritDoc
     */
    public function setCode(string $code): SourceInterface
    {
        return $this->setData(self::CODE, $code);
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return (string)$this->getData(self::LABEL);
    }

    /**
     * @inheritDoc
     */
    public function setLabel(string $label): SourceInterface
    {
        return $this->setData(self::LABEL, $label);
    }

    /**
     * @inheritDoc
     */
    public function isActive(): bool
    {
        return (bool)(int)$this->getData(self::IS_ACTIVE);
    }

    /**
     * @inheritDoc
     */
    public function setIsActive(bool $isActive): SourceInterface
    {
        return $this->setData(self::IS_ACTIVE, $isActive ? 1 : 0);
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return (int)$this->getData(self::SORT_ORDER);
    }

    /**
     * @inheritDoc
     */
    public function setSortOrder(int $sortOrder): SourceInterface
    {
        return $this->setData(self::SORT_ORDER, $sortOrder);
    }
}
