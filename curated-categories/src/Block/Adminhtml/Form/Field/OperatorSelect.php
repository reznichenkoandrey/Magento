<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;
use Scr1be\CuratedCategories\Model\Config\Source\Operator;

/**
 * The operator column of the exclusion-rules grid.
 *
 * `Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray::renderCellTemplate()`
 * drives a column renderer through `setInputName()`, `setInputId()`, `setColumnName()` and
 * `setColumn()` before calling `toHtml()`. `Select` has no `setInputName`/`setInputId` of its own, so
 * without these two adapters the generated markup would carry no name and no id and the column would
 * silently post nothing.
 */
class OperatorSelect extends Select
{
    public function __construct(
        Context $context,
        private readonly Operator $operatorSource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setInputName($value): self
    {
        $this->setName($value);

        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setInputId($value): self
    {
        return $this->setId($value);
    }

    protected function _toHtml(): string
    {
        if (!$this->getOptions()) {
            foreach ($this->operatorSource->toOptionArray() as $option) {
                $this->addOption($option['value'], $option['label']);
            }
        }

        return parent::_toHtml();
    }
}
