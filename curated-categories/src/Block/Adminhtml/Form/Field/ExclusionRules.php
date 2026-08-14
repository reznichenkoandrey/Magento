<?php
declare(strict_types=1);

namespace Scr1be\CuratedCategories\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;

/**
 * The attribute / operator / value grid behind the New Arrivals exclusion rules.
 *
 * The attribute column is a free-text box rather than a dropdown of every product attribute, and
 * that is a considered trade rather than laziness: a real catalogue has several hundred attributes,
 * a select of them is unusable, and the codes a merchant excludes on are the handful they already
 * know by name. The cost is a typo silently matching nothing, which
 * `Scr1be\CuratedCategories\Model\Exclusion\ProductFilter` turns into a warning in the log instead
 * of an exception in cron.
 */
class ExclusionRules extends AbstractFieldArray
{
    private ?OperatorSelect $operatorRenderer = null;

    protected function _prepareToRender(): void
    {
        $this->addColumn(
            'attribute',
            [
                'label' => __('Attribute code'),
                'class' => 'required-entry admin__control-text',
            ]
        );
        $this->addColumn(
            'operator',
            [
                'label' => __('Operator'),
                'renderer' => $this->getOperatorRenderer(),
            ]
        );
        $this->addColumn(
            'value',
            [
                'label' => __('Value'),
                'class' => 'admin__control-text',
            ]
        );

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add rule');
    }

    /**
     * Re-select the saved operator when an existing row is rendered.
     *
     * The grid's rows are drawn from a JavaScript template, so the only way to mark an option
     * selected is the `option_extra_attrs` map the template reads, keyed by the renderer's own
     * option hash.
     */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $operator = (string) $row->getData('operator');

        $row->setData(
            'option_extra_attrs',
            ['option_' . $this->getOperatorRenderer()->calcOptionHash($operator) => 'selected="selected"']
        );
    }

    private function getOperatorRenderer(): OperatorSelect
    {
        if ($this->operatorRenderer === null) {
            /** @var OperatorSelect $renderer */
            $renderer = $this->getLayout()->createBlock(
                OperatorSelect::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
            $renderer->setClass('admin__control-select');

            $this->operatorRenderer = $renderer;
        }

        return $this->operatorRenderer;
    }
}
