<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model;

use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Scr1be\HyvaProductSlider\Api\Data\SliderInterface;
use Scr1be\HyvaProductSlider\Api\Data\SliderSearchResultsInterface;
use Scr1be\HyvaProductSlider\Api\Data\SliderSearchResultsInterfaceFactory;
use Scr1be\HyvaProductSlider\Api\SliderRepositoryInterface;
use Scr1be\HyvaProductSlider\Model\ResourceModel\Slider as SliderResource;
use Scr1be\HyvaProductSlider\Model\ResourceModel\Slider\CollectionFactory;

/**
 * The only supported way to read or write a slider.
 *
 * It is thin on purpose — validation lives in {@see SliderValidator}, storage in the resource model —
 * and the two things it does add are worth naming: it converts resource-level failures into the
 * exceptions the admin controllers already know how to present, and it memoises loads so that a page
 * rendering the same slider in two places does not read it twice.
 *
 * The memo is keyed by id and dropped on every write. It is a per-request cache, not a persistent
 * one: sliders change rarely, but a repository that hands back a stale row after a save is worse than
 * one that never cached at all.
 */
class SliderRepository implements SliderRepositoryInterface
{
    /** @var array<int, SliderInterface> */
    private array $byId = [];

    /** @var array<string, int> */
    private array $idsByIdentifierKey = [];

    public function __construct(
        private readonly SliderResource $resource,
        private readonly SliderFactory $sliderFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly SliderSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly SliderValidator $validator
    ) {
    }

    public function save(SliderInterface $slider): SliderInterface
    {
        $this->validator->validate($slider);

        try {
            /** @var Slider $slider */
            $this->resource->save($slider);
        } catch (LocalizedException $e) {
            throw new CouldNotSaveException(__($e->getMessage()), $e);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(__('The slider could not be saved.'), $e);
        }

        $this->resetMemo();

        return $slider;
    }

    public function getById(int $sliderId): SliderInterface
    {
        if (isset($this->byId[$sliderId])) {
            return $this->byId[$sliderId];
        }

        $slider = $this->sliderFactory->create();
        $this->resource->load($slider, $sliderId);

        if (!$slider->getId()) {
            throw new NoSuchEntityException(__('No slider with id "%1" exists.', $sliderId));
        }

        return $this->byId[$sliderId] = $slider;
    }

    public function getByIdentifier(string $identifier, ?int $storeId = null): SliderInterface
    {
        $key = $identifier . '|' . ($storeId ?? 'any');

        if (!isset($this->idsByIdentifierKey[$key])) {
            $sliderId = $this->resource->getSliderIdByIdentifier($identifier, $storeId);

            if ($sliderId === null) {
                throw new NoSuchEntityException(__('No slider named "%1" exists here.', $identifier));
            }

            $this->idsByIdentifierKey[$key] = $sliderId;
        }

        return $this->getById($this->idsByIdentifierKey[$key]);
    }

    public function getList(SearchCriteriaInterface $searchCriteria): SliderSearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        /** @var SliderSearchResultsInterface $searchResults */
        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    public function delete(SliderInterface $slider): void
    {
        try {
            /** @var Slider $slider */
            $this->resource->delete($slider);
        } catch (\Throwable $e) {
            throw new CouldNotDeleteException(__('The slider could not be deleted.'), $e);
        }

        $this->resetMemo();
    }

    public function deleteById(int $sliderId): void
    {
        $this->delete($this->getById($sliderId));
    }

    private function resetMemo(): void
    {
        $this->byId = [];
        $this->idsByIdentifierKey = [];
    }
}
