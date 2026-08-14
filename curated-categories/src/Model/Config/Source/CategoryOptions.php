<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Model\Config\Source;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * A flat, indented category list for the three "which category" pickers.
 *
 * Flat rather than the tree widget because each field holds one id and the list is read once —
 * `Magento_Catalog`'s tree component is a great deal of JavaScript to pick a single value, and the
 * system config form is not where it lives anyway. Depth is carried by indentation so that "Tops"
 * under Men and "Tops" under Women are distinguishable, which they are not in a bare alphabetical
 * list.
 *
 * Levels 0 and 1 — the tree root and the per-store root categories — are excluded. Reconciling a
 * store root would put a merchandising feed's twenty-four products in the container every other
 * category hangs from, and it is not a mistake worth making available in a dropdown.
 */
class CategoryOptions implements OptionSourceInterface
{
    private const ROOT_LEVEL = 1;
    private const INDENT = '— ';

    /** @var array<int, array{value: int|string, label: string}>|null */
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

        $options = [['value' => '', 'label' => (string) __('-- Not configured --')]];

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
