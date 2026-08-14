<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Api\Data\SliderSearchResultsInterface;

interface SliderRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(SliderInterface $slider): SliderInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $sliderId): SliderInterface;

    /**
     * Identifier lookup is the storefront's entry point — layout XML and the widget both address a
     * slider by name, never by autoincrement id, so that a slider survives being re-created.
     *
     * @throws NoSuchEntityException
     */
    public function getByIdentifier(string $identifier, ?int $storeId = null): SliderInterface;

    public function getList(SearchCriteriaInterface $searchCriteria): SliderSearchResultsInterface;

    /**
     * @throws CouldNotDeleteException
     */
    public function delete(SliderInterface $slider): void;

    /**
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     */
    public function deleteById(int $sliderId): void;
}
