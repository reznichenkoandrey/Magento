<?php
declare(strict_types=1);

namespace Scr1be\CouponTicket\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\Rule;

/**
 * The rules a coupon ticket can point at.
 *
 * Filtered to active rules with a **specific** coupon, because those are the only ones that have a
 * code to print. A NO_COUPON rule applies by itself and an AUTO rule mints a code per customer;
 * offering either in this list would let an author build a ticket that renders with an empty code
 * and no explanation.
 *
 * `Magento\Widget\Block\Adminhtml\Widget\Options` resolves `source_model` through
 * `Magento\Framework\Option\ArrayPool::get()`, which rejects anything that is not a
 * `Magento\Framework\Data\OptionSourceInterface` — hence this interface and not the deprecated
 * `Option\ArrayInterface`.
 */
class CartPriceRule implements OptionSourceInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @return array<int, array{value: int|string, label: string}>
     */
    public function toOptionArray(): array
    {
        // Full rows, not two columns: this collection's `_afterLoad()` runs `mapAssociatedEntities()`
        // twice to attach website and customer-group ids, and narrowing the select is a way to find
        // out which columns it quietly needed. A cart price rule table is a few dozen rows.
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter('coupon_type', Rule::COUPON_TYPE_SPECIFIC);
        $collection->setOrder('name', 'ASC');

        $options = [['value' => '', 'label' => (string)__('-- Please select --')]];

        foreach ($collection as $rule) {
            $options[] = [
                'value' => (int)$rule->getId(),
                'label' => (string)$rule->getName(),
            ];
        }

        return $options;
    }
}
