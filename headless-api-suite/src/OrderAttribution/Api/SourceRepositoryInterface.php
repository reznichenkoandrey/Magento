<?php
declare(strict_types=1);

namespace Scr1be\OrderAttribution\Api;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Scr1be\OrderAttribution\Api\Data\SourceInterface;

/**
 * @api
 */
interface SourceRepositoryInterface
{
    /**
     * @param SourceInterface $source
     * @return SourceInterface
     * @throws CouldNotSaveException
     */
    public function save(SourceInterface $source): SourceInterface;

    /**
     * @param int $sourceId
     * @return SourceInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $sourceId): SourceInterface;

    /**
     * @param string $code
     * @return SourceInterface
     * @throws NoSuchEntityException
     */
    public function getByCode(string $code): SourceInterface;

    /**
     * @param SourceInterface $source
     * @return void
     * @throws CouldNotDeleteException
     */
    public function delete(SourceInterface $source): void;

    /**
     * Every source that new orders may currently be attributed to, in display order.
     *
     * @return SourceInterface[]
     */
    public function getActive(): array;
}
