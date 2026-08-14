<?php
declare(strict_types=1);

namespace Scr1be\HyvaProductSlider\Model\Source;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * A flat, indented category list for the form's category picker.
 *
 * Flat rather than a tree component because the field is one value and the list is read once —
 * `Magento_Catalog`'s tree widget is a good deal of JavaScript to pick a single id. Depth is carried
 * by indentation so that "Tops" under Men and "Tops" under Women are distinguishable, which they are
 * not in a bare alphabetical list.
 *
 * Root categories (level 1) are excluded: they are per-store containers, never something a
 * merchandiser means by "this category".
 */
class CategoryOptions implements OptionSourceInterface
{
    private const ROOT_LEVEL = 1;

    private const INDENT = '— ';

    /** @var array<int, array{value: int, label: string}>|null */
    private ?array $options = null;

    public function __construct(private readonly CollectionFactory $categoryCollectionFactory)
    {
    }

    /**
     * @return array<int, array{value: int|string, label: string}>
     */
    public function toOptionArray(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $collection = $this->categoryCollectionFactory->create();
        $collection->addAttributeToSelect('name')
            ->addFieldToFilter('level', ['gt' => self::ROOT_LEVEL])
            // Path order is tree order: a child always follows its parent, so indentation lines up.
            ->setOrder('path', 'ASC');

        $options = [['value' => '', 'label' => (string) __('-- Please Select --')]];

        foreach ($collection as $category) {
            /** @var CategoryInterface $category */
            $depth = max(0, (int) $category->getLevel() - self::ROOT_LEVEL - 1);

            $options[] = [
                'value' => (int) $category->getId(),
                'label' => str_repeat(self::INDENT, $depth) . (string) $category->getName(),
            ];
        }

        return $this->options = $options;
    }
}
