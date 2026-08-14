<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Model;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Scr1be\OrderAttribution\Api\Data\SourceInterface;
use Scr1be\OrderAttribution\Api\SourceRepositoryInterface;
use Scr1be\OrderAttribution\Model\ResourceModel\Source as SourceResource;
use Scr1be\OrderAttribution\Model\ResourceModel\Source\CollectionFactory;

/**
 * @inheritDoc
 */
class SourceRepository implements SourceRepositoryInterface
{
    /**
     * @param SourceResource $resource
     * @param SourceFactory $sourceFactory
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly SourceResource $resource,
        private readonly SourceFactory $sourceFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function save(SourceInterface $source): SourceInterface
    {
        try {
            $this->resource->save($source);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(new Phrase('Could not save the source: %1', [$e->getMessage()]), $e);
        }

        return $source;
    }

    /**
     * @inheritDoc
     */
    public function getById(int $sourceId): SourceInterface
    {
        $source = $this->sourceFactory->create();
        $this->resource->load($source, $sourceId);

        if (!$source->getSourceId()) {
            throw new NoSuchEntityException(new Phrase('No order source with ID %1 exists.', [$sourceId]));
        }

        return $source;
    }

    /**
     * @inheritDoc
     */
    public function getByCode(string $code): SourceInterface
    {
        $source = $this->sourceFactory->create();
        $this->resource->load($source, $code, SourceInterface::CODE);

        if (!$source->getSourceId()) {
            throw new NoSuchEntityException(new Phrase('No order source with code "%1" exists.', [$code]));
        }

        return $source;
    }

    /**
     * @inheritDoc
     */
    public function delete(SourceInterface $source): void
    {
        try {
            $this->resource->delete($source);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(new Phrase('Could not delete the source: %1', [$e->getMessage()]), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function getActive(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(SourceInterface::IS_ACTIVE, 1);
        $collection->setOrder(SourceInterface::SORT_ORDER, 'ASC');
        $collection->setOrder(SourceInterface::CODE, 'ASC');

        return array_values($collection->getItems());
    }
}
